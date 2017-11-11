<?php namespace Jackiedo\LogReader\Entities;

use Jackiedo\LogReader\Contracts\LogParser;

/**
 * The LogContext class.
 *
 * @package Jackiedo\LogReader
 * @author Jackie Do <anhvudo@gmail.com>
 * @copyright 2017
 * @access public
 */
class LogContext
{
    /**
     * Store message of the log context
     *
     * @var string
     */
    public $message;

    /**
     * Store exception in the log context
     *
     * @var string
     */
    public $exception;

    /**
     * Store location of the log context
     *
     * @var string
     */
    public $in;

    /**
     * Store the line in file
     *
     * @var int
     */
    public $line;

    /**
     * Store instance of LogParser for parsing content of the log context
     *
     * @var \Jackiedo\LogReader\LogParser
     */
    protected $parser;

    /**
     * Store original log context
     *
     * @var string
     */
    protected $content;

	protected $format_version;

	/**
	 * Create instance of log context
	 *
	 * @param LogParser|object $parser
	 * @param  string $content
	 *
	 * @param string $format_version
	 */
    public function __construct(LogParser $parser, $content, $format_version = "laravel53")
    {
        $this->parser  = $parser;
        $this->content = $content;
        $this->format_version = $format_version;

        $this->assignAttributes();
    }

    /**
     * Return content if the log context is used as string
     *
     * @return string
     */
    public function __toString()
    {
        return $this->content;
    }

    /**
     * Parses content of the log context and assigns each information
     * to the corresponding attribute in log context
     *
     * @return void
     */
    protected function assignAttributes()
    {
        $parsed = $this->parser->parseLogContext($this->content, $this->format_version);

        foreach ($parsed as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
