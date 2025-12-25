<?php

namespace AppKit\App;

use AppKit\Log\Logger;
use AppKit\Log\Handler\StdoutHandler;
use AppKit\StartStop\StartStopSequence;
use AppKit\Async\Task;
use AppKit\Async\CanceledException;

use Throwable;
use React\EventLoop\Loop;
use React\EventLoop\ExtUvLoop;
use function React\Async\async;
use function React\Async\await;

abstract class AbstractApp {
    protected const VENDOR = null;
    protected const DOMAIN = null;
    protected const NAME = null;

    protected $log;
    protected $s3;

    private $startTask;
    private $stopTask;

    abstract protected function init($config);
    
    function __construct($config) {
        $this -> log = new Logger($this);
        if(isset($config['log']['local']['level'])) {
            $this -> log -> addHandler(
                new StdoutHandler($config['log']['local']['printStackTraces'] ?? false),
                $config['log']['local']['level']
            );
        }

        $this -> s3 = new StartStopSequence($this -> log);

        $this -> init($config);
    }

    public function run() {
        Loop::futureTick(async(function() {
            if(! (Loop::get() instanceof ExtUvLoop))
                $this -> log -> warning('AppKit performance can be improved by enabling ext-uv');

            $this -> startTask = new Task(function() {
                return $this -> startRoutine();
            });
            $this -> stopTask = new Task(function() {
                return $this -> stopRoutine();
            });

            $this -> log -> info(
                'Starting app '.
                static::VENDOR.
                '/'.
                (static::DOMAIN ? static::DOMAIN.'.' : '').
                static::NAME.
                '...'
            );

            try {
                $this -> startTask -> run() -> await();
                $this -> log -> info('App started');
            } catch(CanceledException $e) {
                $this -> log -> info('App start canceled');
                $this -> stop();
            } catch(Throwable $e) {
                $this -> log -> error('Failed to start app', $e);
                $this -> stop();
            }

            try {
                $this -> stopTask -> await();
                $this -> log -> info('App stopped');
            } catch(Throwable $e) {
                $this -> log -> error('Failed to stop app', $e);
                $this -> stop();
            }
        }));
    }

    public function signal($signal) {
        Loop::futureTick(function() use($signal) {
            $this -> log -> warning("Received signal $signal");
            $this -> stop();
        });
    }

    public function stop() {
        $startStatus = $this -> startTask -> getStatus();
        $stopStatus = $this -> stopTask -> getStatus();

        if($stopStatus != Task::PENDING || $startStatus == Task::CANCELING) {
            $this -> log -> warning('Forcing app stop...');
            Loop::stop();
            return;
        }

        if($startStatus == Task::PENDING || $startStatus == Task::RUNNING) {
            $this -> log -> debug('Canceling app start...');
            $this -> startTask -> cancel();
        } else {
            $this -> log -> debug('Stopping app...');
            $this -> stopTask -> run();
        }
    }

    private function startRoutine() {
        foreach([SIGINT, SIGTERM] as $signal)
            Loop::addSignal($signal, [$this, 'signal']);
        $this -> log -> debug('Registered signal handlers');

        $this -> s3 -> start();
    }

    private function stopRoutine() {
        $this -> s3 -> stop();

        foreach([SIGINT, SIGTERM] as $signal)
            Loop::removeSignal($signal, [$this, 'signal']);
        $this -> log -> debug('Unregistered signal handlers');
    }
}
