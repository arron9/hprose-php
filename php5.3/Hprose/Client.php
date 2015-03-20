<?php
/**********************************************************\
|                                                          |
|                          hprose                          |
|                                                          |
| Official WebSite: http://www.hprose.com/                 |
|                   http://www.hprose.org/                 |
|                                                          |
\**********************************************************/

/**********************************************************\
 *                                                        *
 * Hprose/Client.php                                      *
 *                                                        *
 * hprose client class for php 5.3+                       *
 *                                                        *
 * LastModified: Mar 20, 2015                             *
 * Author: Ma Bingyao <andot@hprose.com>                  *
 *                                                        *
\**********************************************************/

namespace Hprose {
    class Proxy {
        private $client;
        private $namespace;
        public function __construct(Client $client, $namespace = '') {
            $this->client = $client;
            $this->namespace = $namespace;
        }
        public function __call($name, array $arguments) {
            $name = $this->namespace . $name;
            $n = count($arguments);
            if ($n > 0) {
                if (is_callable($arguments[$n - 1])) {
                    $callback = array_pop($arguments);
                    $this->client->invoke($name, $arguments, false, ResultMode::Normal, false, $callback);
                    return;
                }
            }
            return $this->client->invoke($name, $arguments);
        }
        public function __get($name) {
            return new Proxy($this->client, $this->namespace . $name . '_');
        }
    }

    abstract class Client extends Proxy {
        protected $url;
        private $filters;
        private $simple;
        protected function sendAndReceive($request) {
           throw new \Exception("This client can't support synchronous invoke.");
        }
        protected function asyncSendAndReceive($request, $callback) {
            throw new \Exception("This client can't support asynchronous invoke.");
        }
        public function __construct($url = '') {
            $this->url = $url;
            $this->filters = array();
            $this->simple = false;
            parent::__construct($this, '');
        }
        public function useService($url = '', $namespace = '') {
            if ($url) {
                $this->url = $url;
            }
            if ($namespace) {
                $namespace .= "_";
            }
            return new Proxy($this, $namespace);
        }
        private function doOutput($name, &$args, $byref, $simple, $context) {
            if ($simple === null) {
                $simple = $this->simple;
            }
            $stream = new BytesIO(Tags::TagCall);
            $writer = new Writer($stream, $simple);
            $writer->writeString($name);
            if (count($args) > 0 || $byref) {
                $writer->reset();
                $writer->writeArray($args);
                if ($byref) {
                    $writer->writeBoolean(true);
                }
            }
            $stream->write(Tags::TagEnd);
            $request = $stream->toString();
            $count = count($this->filters);
            for ($i = 0; $i < $count; $i++) {
                $request = $this->filters[$i]->outputFilter($request, $context);
            }
            $stream->close();
            return $request;
        }
        private function doInput($response, &$args, $mode, $context) {
            $count = count($this->filters);
            for ($i = $count - 1; $i >= 0; $i--) {
                $response = $this->filters[$i]->inputFilter($response, $context);
            }
            if ($mode == ResultMode::RawWithEndTag) {
                return $response;
            }
            if ($mode == ResultMode::Raw) {
                return substr($response, 0, -1);
            }
            $stream = new BytesIO($response);
            $reader = new Reader($stream);
            $result = null;
            while (($tag = $stream->getc()) !== Tags::TagEnd) {
                switch ($tag) {
                    case Tags::TagResult:
                        if ($mode == ResultMode::Serialized) {
                            $result = $reader->readRaw()->toString();
                        }
                        else {
                            $reader->reset();
                            $result = $reader->unserialize();
                        }
                        break;
                    case Tags::TagArgument:
                        $reader->reset();
                        $_args = $reader->readList();
                        $n = min(count($_args), count($args));
                        for ($i = 0; $i < $n; $i++) {
                            $args[$i] = $_args[$i];
                        }
                        break;
                    case Tags::TagError:
                        $reader->reset();
                        throw new \Exception($reader->readString());
                        break;
                    default:
                        throw new \Exception("Wrong Response: \r\n" . $response);
                        break;
                }
            }
            return $result;
        }
        public function invoke($name, &$args = array(), $byref = false, $mode = ResultMode::Normal, $simple = null, $callback = null) {
            $context = new \stdClass();
            $context->client = $this;
            $context->userdata = new \stdClass();
            $request = $this->doOutput($name, $args, $byref, $simple, $context);
            if (is_callable($callback)) {
                $self = $this;
                $this->asyncSendAndReceive($request, function($response, $error) use ($self, &$args, $mode, $context, $callback) {
                    $result = null;
                    $callback = new \ReflectionFunction($callback);
                    $n = $callback->getNumberOfParameters();
                    if ($n === 3) {
                        if ($error === null) {
                            try {
                                $result = $self->doInput($response, $args, $mode, $context);
                            }
                            catch (\Exception $e) {
                                $error = $e;
                            }
                        }
                        $callback->invoke($result, $args, $error);
                    }
                    else {
                        if ($error !== null) throw $error;
                        $result = $self->doInput($response, $args, $mode, $context);
                        switch($n) {
                            case 0:
                                $callback->invoke(); break;
                            case 1:
                                $callback->invoke($result); break;
                            case 2:
                                $callback->invoke($result, $args); break;
                        }
                    }
                });
            }
            else {
                $response = $this->sendAndReceive($request);
                return $this->doInput($response, $args, $mode, $context);
            }
        }
        public function getFilter() {
            if (count($this->filters) === 0) {
                return null;
            }
            return $this->filters[0];
        }
        public function setFilter(Filter $filter) {
            $this->filters = array();
            if ($filter !== null) {
                $this->filters[] = $filter;
            }
        }
        public function addFilter(Filter $filter) {
            $this->filters[] = $filter;
        }
        public function removeFilter(Filter $filter) {
            $i = array_search($filter, $this->filters);
            if ($i === false || $i === null) {
                return false;
            }
            $this->filters = array_splice($this->filters, $i, 1);
            return true;
        }
        public function getSimpleMode() {
            return $this->simple;
        }
        public function setSimpleMode($simple = true) {
            $this->simple = $simple;
        }
    }

}
