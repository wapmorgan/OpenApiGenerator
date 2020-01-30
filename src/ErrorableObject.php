<?php
namespace wapmorgan\OpenApiGenerator;

class ErrorableObject
{
    /**
     * @var callable|null Callback for cases when an error occured
     */
    protected $onErrorCallback;

    /**
     * @var callable|null Callback for cases when a notice was generated
     */
    protected $onNoticeCallback;

    public const NOTICE_SUCCESS = 1,
        NOTICE_IMPORTANT = 2,
        NOTICE_INFO = 3,
        NOTICE_WARNING = 4,
        NOTICE_ERROR = 5;

    /**
     * @param callable $callback
     * @return ErrorableObject
     */
    public function setOnErrorCallback(?callable $callback): ErrorableObject
    {
        $this->onErrorCallback = $callback;
        return $this;
    }

    /**
     * @param callable $callback
     * @return ErrorableObject
     */
    public function setOnNoticeCallback(?callable $callback): ErrorableObject
    {
        $this->onNoticeCallback = $callback;
        return $this;
    }

    /**
     * @param $message
     * @throws \Exception
     */
    public function error(string $message)
    {
        if ($this->onErrorCallback !== null) {
            call_user_func($this->onErrorCallback, $message);
        } else {
            throw new \Exception($message);
        }
    }

    /**
     * @param string $message
     * @param int $level
     */
    public function notice(string $message, int $level)
    {
        if ($this->onNoticeCallback !== null) {
            call_user_func($this->onNoticeCallback, $message, $level);
        } else {
            echo $message . PHP_EOL;
        }
    }
}