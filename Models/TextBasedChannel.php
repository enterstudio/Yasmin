<?php
/**
 * Yasmin
 * Copyright 2017 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Yasmin/blob/master/LICENSE
*/

namespace CharlotteDunois\Yasmin\Models;

/**
 * The text based channel.
 */
class TextBasedChannel extends ClientBase
    implements \CharlotteDunois\Yasmin\Interfaces\ChannelInterface,
                \CharlotteDunois\Yasmin\Interfaces\TextChannelInterface { //TODO: Implementation
                    
    protected $messages;
    protected $typings;
    protected $typingTriggered = array(
        'count' => 0,
        'timer' => null
    );
    
    protected $id;
    protected $type;
    protected $lastMessageID;
    
    protected $createdTimestamp;
    
    function __construct(\CharlotteDunois\Yasmin\Client $client, $channel) {
        parent::__construct($client);
        
        $this->messages = new \CharlotteDunois\Yasmin\Utils\Collection();
        $this->typings = new \CharlotteDunois\Yasmin\Utils\Collection();
        
        $this->id = $channel['id'];
        $this->type = \CharlotteDunois\Yasmin\Constants::CHANNEL_TYPES[$channel['type']];
        $this->lastMessageID = $channel['last_message_id'] ?? null;
        
        $this->createdTimestamp = (int) \CharlotteDunois\Yasmin\Utils\Snowflake::deconstruct($this->id)->timestamp;
    }
    
    /**
     * @property-read string                                       $id                 The channel ID.
     * @property-read string                                       $type               The channel type ({@see \CharlotteDunois\Yasmin\Constants::CHANNEL_TYPES}).
     * @property-read string|null                                  $lastMessageID      The last message ID, or null.
     * @property-read int                                          $createdTimestamp   The timestamp of when this channel was created.
     * @property-read \CharlotteDunois\Yasmin\Utils\Collection    $messages           The collection with all cached messages.
     *
     * @property-read \DateTime                                    $createdAt          The DateTime object of createdTimestamp.
     * @property-read \CharlotteDunois\Yasmin\Models\Message|null  $lastMessage        The last message, or null.
     */
    function __get($name) {
        if(\property_exists($this, $name)) {
            return $this->$name;
        }
        
        switch($name) {
            case 'createdAt':
                return \CharlotteDunois\Yasmin\Utils\DataHelpers::makeDateTime($this->createdTimestamp);
            break;
            case 'lastMessage':
                if(!empty($this->lastMessageID) && $this->messages->has($this->lastMessageID)) {
                    return $this->messages->get($this->lastMessageID);
                }
            break;
        }
        
        return null;
    }
    
    /**
     * Deletes multiple messages at once.
     * @param \CharlotteDunois\Yasmin\Utils\Collection|array|int  $messages  A collection or array of Message objects, or the number of messages to delete (2-100).
     * @param string                                               $reason
     * @return \React\Promise\Promise<this>
     */
    function bulkDelete($messages, string $reason = '') {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($messages, $reason) {
            if(\is_int($messages)) {
                if($messages < 2 || $messages > 100) {
                    return $reject(new \InvalidArgumentException('Can not bulk delete less than 2 or more than 100 messages'));
                }
                
                $messages = $this->messages->slice(($this->messages->count() - $messages), $messages);
            }
            
            if($messages instanceof \CharlotteDunois\Yasmin\Utils\Collection) {
                $messages = $messages->all();
            }
            
            $messages = \array_filter($messages, function ($message) {
                return $message->id;
            });
            
            $this->client->apimanager()->endpoints->channel->bulkDeleteMessages($this->id, $messages, $reason)->then(function ($data) use ($resolve) {
                $resolve($this);
            }, $reject);
        }));
    }
    
    /**
     * Collects messages during a specific duration (and max. amount). Options are as following:
     *
     *  array(
     *      'time' => int, (duration, in seconds, default 30)
     *      'max' => int, (max. messages to collect)
     *      'errors' => array, (optional, which failed "conditions" (max not reached in time ("time")) lead to a rejected promise, defaults to [])
     *  )
     *
     * @param callable  $filter   The filter to only collect desired messages.
     * @param array     $options  The collector options.
     * @return \React\Promise\Promise<\CharlotteDunois\Collect\Collection>
     *
     */
    function collectMessages(callable $filter, array $options) {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) {
            $collect = new \CharlotteDunois\Yasmin\Utils\Collection();
            $timer = null;
            
            $listener = function ($message) use ($collect, $filter, &$listener, $options, $resolve, $reject, &$timer) {
                if($message->channel->id === $this->id && $filter($message)) {
                    $collect->set($message->id, $message);
                    
                    if($collect->count() >= $options['max']) {
                        $this->client->removeListener('message', $listener);
                        if($timer) {
                            $this->client->cancelTimer($timer);
                        }
                        
                        $resolve($collect);
                    }
                }
            };
            
            $timer = $this->client->addTimer((int) ($options['time'] ?? 30), function() use ($collect, &$listener, $options, $resolve, $reject) {
                $this->client->removeListener('message', $listener);
                
                if(\in_array('time', $options['errors']) && $collect->count < $options['max']) {
                    return $reject(new \RangeException('Not reached max messages in specified duration'));
                }
                
                $resolve($collect);
            });
            
            $this->client->on('message', $listener);
        }));
    }
    
    /**
     * Fetches a specific message using the ID. Bot account endpoint only.
     * @param  string  $id
     * @return \React\Promise\Promise<\CharlotteDunois\Yasmin\Models\Message>
     */
    function fetchMessage(string $id) {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($id) {
            $this->client->apimanager()->endpoints->channel->getChannelMessage($this->id, $id)->then(function ($data) use ($resolve) {
                $message = $this->_createMessage($data);
                $resolve($message);
            }, $reject);
        }));
    }
    
    /**
     * Fetches messages of this channel. Options are as following:
     *
     *  array(
     *      'after' => string, (message ID)
     *      'around' => string, (message ID)
     *      'before' => string, (message ID)
     *      'limit' => int, (1-100, defaults to 50)
     *  )
     *
     * @param  array  $options
     * @return \React\Promise\Promise<\CharlotteDunois\Yasmin\Utils\Collection<\CharlotteDunois\Yasmin\Models\Message>>
     */
    function fetchMessages(array $options = array()) {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($options) {
            $this->client->apimanager()->endpoints->channel->getChannelMessages($this->id, $options)->then(function ($data) use ($resolve) {
                $collect = new \CharlotteDunois\Yasmin\Utils\Collection();
                
                foreach($data as $m) {
                    $message = $this->_createMessage($m);
                    $collect->set($message->id, $message);
                }
                
                $resolve($collect);
            }, $reject);
        }));
    }
    
    /**
     * Sends a message to a channel. Options are as following (all are optional):
     *
     *  array(
     *    'embed' => array|\CharlotteDunois\Yasmin\Models\MessageEmbed, (an (embed) array or instance of MessageEmbed)
     *    'files' => array, (an array of array('name', 'data' || 'path') (associative) or just plain file contents, file paths or URLs)
     *    'split' => bool|array, (array: array('before', 'after', 'char', 'maxLength') (associative) | before: The string to insert before the split, after: The string to insert after the split, char: The string to split on, maxLength: The max. length of each message)
     *  )
     *
     * @param  string  $message  The message content.
     * @param  array   $options  Any message options.
     * @return \React\Promise\Promise<\CharlotteDunois\Yasmin\Models\Message|\CharlotteDunois\Yasmin\Utils\Collection<\CharlotteDunois\Yasmin\Models\Message>>
     */
    function send(string $message, array $options = array()) {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($message, $options) {
            if(!empty($options['files'])) {
                $promises = array();
                foreach($options['files'] as $file) {
                    if(\is_string($file)) {
                        if(\filter_var($file, FILTER_VALIDATE_URL)) {
                            $promises[] = \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData($file)->then(function ($data) use ($file) {
                                return array('name' => \basename($file), 'data' => $data);
                            });
                        } else {
                            $promises[] = \React\Promise\resolve(array('name' => 'file-'.\bin2hex(\random_bytes(3)).'.jpg', 'data' => $file));
                        }
                        
                        continue;
                    }
                    
                    if(!\is_array($file)) {
                        continue;
                    }
                    
                    if(!isset($file['name'])) {
                        if(isset($file['path'])) {
                            $file['name'] = \basename($file['path']);
                        } else {
                            $file['name'] = 'file-'.\bin2hex(\random_bytes(3)).'.jpg';
                        }
                    }
                    
                    if(!isset($file['data']) && filter_var($file['path'], FILTER_VALIDATE_URL)) {
                        $promises[] = \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData($file['path'])->then(function ($data) use ($file) {
                            $file['data'] = $data;
                            return $file;
                        });
                    } else {
                        $promises[] = \React\Promise\resolve($file);
                    }
                }
                
                $files = \React\Promise\all($promises);
            } else {
                $files = \React\Promise\resolve();
            }
            
            $files->then(function ($files = null) use ($message, $options, $resolve, $reject) {
                $msg = array(
                    'content' => $message
                );
                
                if(!empty($options['embed'])) {
                    $msg['embed'] = $options['embed'];
                }
                
                if(!empty($options['split'])) {
                    $split = array('before' => '', 'after' => '', 'char' => "\n", 'maxLength' => 1950);
                    if(\is_array($options['split'])) {
                        $split = \array_merge($split, $options['split']);
                    }
                    
                    if(\strlen($msg['content']) > $split['maxLength']) {
                        $collection = new \CharlotteDunois\Yasmin\Utils\Collection();
                        
                        $chunkedSend = function ($msg, $files = null) use ($collection, $reject) {
                            return $this->client->apimanager()->endpoints->channel->createMessage($this->id, $msg, ($files ?? array()))->then(function ($response) use ($collection) {
                                $msg = $this->_createMessage($response);
                                $collection->set($msg->id, $msg);
                            }, $reject);
                        };
                        
                        $i = 0;
                        $messages = array();
                        
                        $parts = \explode($split['char'], $msg['content']);
                        foreach($parts as $part) {
                            if(empty($messages[$i])) {
                                $messages[$i] = '';
                            }
                            
                            if((\strlen($messages[$i]) + \strlen($part) + 2) >= $split['maxLength']) {
                                $i++;
                                $messages[$i] = '';
                            }
                            
                            $messages[$i] .= $part.$split['char'];
                        }
                        
                        $promise = \React\Promise\resolve();
                        foreach($messages as $key => $message) {
                            $promise = $promise->then(function () use ($chunkedSend, &$files, $key, $i, $message, &$msg, $split) {
                                $fs = null;
                                if($files) {
                                    $fs = $files;
                                    $files = nulL;
                                }
                                
                                $message = array(
                                    'content' => ($key > 0 ? $split['before'] : '').$message.($key < $i ? $split['after'] : '')
                                );
                                
                                if(!empty($msg['embed'])) {
                                    $message['embed'] = $msg['embed'];
                                    $msg['embed'] = null;
                                }
                                
                                return $chunkedSend($message, $fs);
                            }, $reject);
                        }
                        
                        return $promise->then(function () use ($collection, $resolve) {
                            $resolve($collection);
                        }, $reject);
                    }
                }
                
                $this->client->apimanager()->endpoints->channel->createMessage($this->id, $msg, ($files ?? array()))->then(function ($response) use ($resolve) {
                    $resolve($this->_createMessage($response));
                }, $reject);
            }, $reject);
        }));
    }
    
    /**
     * Starts sending the typing indicator in this channel. Counts up a triggered typing counter.
     */
    function startTyping() {
        if($this->typingTriggered['count'] === 0) {
            $this->typingTriggerd['timer'] = $this->client->addPeriodicTimer(7, function () {
                $this->client->apimanager()->endpoints->channel->triggerChannelTyping($this->id)->then(function () {
                    $this->_updateTyping($this->client->user, \time());
                }, function () {
                    $this->_updateTyping($this->client->user);
                    $this->typingTriggered['count'] = 0;
                    
                    if($this->typingTriggerd['timer']) {
                        $this->client->cancelTimer($this->typingTriggerd['timer']);
                        $this->typingTriggerd['timer'] = null;
                    }
                });
            });
        }
        
        $this->typingTriggered['count']++;
    }
    
    /**
     * Stops sending the typing indicator in this channel. Counts down a triggered typing counter.
     * @param  bool  $force  Reset typing counter and stop sending the indicator.
     */
    function stopTyping(bool $force = false) {
        if($this->typingCount() === 0) {
            return \React\Promise\resolve();
        }
        
        $this->typingTriggered['count']--;
        if($force) {
            $this->typingTriggered['count'] = 0;
        }
        
        if($this->typingTriggered['count'] === 0) {
            if($this->typingTriggerd['timer']) {
                $this->client->cancelTimer($this->typingTriggerd['timer']);
                $this->typingTriggerd['timer'] = null;
            }
        }
    }
    
    /**
     * Returns the amount of user typing in this channel.
     * @return int
     */
    function typingCount() {
        return $this->typings->count();
    }
    
    /**
     * Determines whether the given user is typing in this channel or not.
     * @param \CharlotteDunois\Yasmin\Models\User  $user
     * @return bool
     */
    function isTyping(\CharlotteDunois\Yasmin\Models\User $user) {
        return $this->typings->has($user->id);
    }
    
    /**
     * Determines whether how long the given user has been typing in this channel. Returns -1 if the user is not typing.
     * @param \CharlotteDunois\Yasmin\Models\User  $user
     * @return int
     */
    function isTypingSince(\CharlotteDunois\Yasmin\Models\User $user) {
        if($this->isTyping($user) === false) {
            return -1;
        }
        
        return (\time() - $this->typings->get($user->id)['timestamp']);
    }
    
    /**
     * @param array  $message
     * @internal
     */
    function _createMessage(array $message) {
        if($this->messages->has($message['id'])) {
            return $this->messages->get($message['id']);
        }
        
        $msg = new \CharlotteDunois\Yasmin\Models\Message($this->client, $this, $message);
        $this->messages->set($msg->id, $msg);
        return $msg;
    }
    
    /**
     * @param \CharlotteDunois\Yasmin\Models\User  $user
     * @param int                                  $timestamp
     * @internal
     */
    function _updateTyping(\CharlotteDunois\Yasmin\Models\User $user, int $timestamp = null) {
        if($timestamp === null) {
            return $this->typings->delete($user->id);
        }
        
        $timer = $this->client->addTimer(6, function ($client) use ($user) {
            $this->typings->delete($user->id);
            $client->emit('typingStop', $this, $user);
        });
        
        $this->typings->set($user->id, array(
            'timestamp' => (int) $timestamp,
            'timer' => $timer
        ));
    }
}
