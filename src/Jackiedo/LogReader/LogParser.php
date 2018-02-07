<?php namespace Jackiedo\LogReader;

use Illuminate\Support\Str;
use Jackiedo\LogReader\Contracts\LogParser as LogParserInterface;

/**
 * The LogParser class.
 *
 * @package Jackiedo\LogReader
 * @author Jackie Do <anhvudo@gmail.com>
 * @copyright 2017
 * @access public
 */
class LogParser implements LogParserInterface
{
    const LOG_DATE_PATTERN            = "\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]";
    const LOG_ENVIRONMENT_PATTERN     = "(\w+)";
    const LOG_LEVEL_PATTERN           = "([A-Z]+)";
    const CONTEXT_EXCEPTION_PATTERN   = "([a-zA-Z\\\]+)\:{1}";
    const CONTEXT_EXCEPTION_PATTERN_L55 = "([a-zA-Z\\\]+\(code\:\s.+\))\:{1}";
    const CONTEXT_MESSAGE_PATTERN     = "\s(.+){1}";
    const CONTEXT_IN_PATTERN          = "(.+)\:(\d+)";
    const STACK_TRACE_DIVIDER_PATTERN = "Stack trace\:";
    const STACK_TRACE_DIVIDER_PATTERN_L55 = "\[stacktrace\]";
    const STACK_TRACE_INDEX_PATTERN   = "\#\d+\s";
    const TRACE_IN_DIVIDER_PATTERN    = "\:\s";
    const TRACE_FILE_PATTERN          = "(.*)\((\d+)\)";

    /**
     * Parses content of the log file into an array containing the necessary information
     *
     * @param  string  $content
     *
     * @return array   Structure is ['headerSet' => [], 'dateSet' => [], 'envSet' => [], 'levelSet' => [], 'bodySet' => []]
     */
    public function parseLogContent($content)
    {
		$headerSet = $dateSet = $envSet = $levelSet = $bodySet = [];

        $pattern = "/^" .self::LOG_DATE_PATTERN. "\s" .self::LOG_ENVIRONMENT_PATTERN. "\." .self::LOG_LEVEL_PATTERN. "\:|Next/m";

        preg_match_all($pattern, $content, $matches);

        if (is_array($matches)) {
            $bodySet = array_map('ltrim', preg_split($pattern, $content));

            if (empty($bodySet[0]) && count($bodySet) > count($matches[0])) {
                array_shift($bodySet);
            }

            $headerSet = $matches[0];
            $dateSet   = $matches[1];
            $envSet    = $matches[2];
            $levelSet  = $matches[3];
        }

        return compact('headerSet', 'dateSet', 'envSet', 'levelSet', 'bodySet');
    }

    /**
     * Parses the body part of the log entry into an array containing the necessary information
     *
     * @param  string  $content
     *
     * @return array   Structure is ['context' => '', 'stack_traces' => '']
     */
    public function parseLogBody($content)
    {
		$pattern      = "/^(".self::STACK_TRACE_DIVIDER_PATTERN_L55.")/m";
		$res = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);

		if (is_array($res) && isset($res[1]) && $res[1] == "[stacktrace]") {
			$stackTraceDividerPattern = self::STACK_TRACE_DIVIDER_PATTERN_L55;
			$format_version = "laravel55";
		} else {
			$stackTraceDividerPattern = self::STACK_TRACE_DIVIDER_PATTERN;
			$format_version = "laravel53";
		}

        $pattern       = "/^" . $stackTraceDividerPattern . "/m";
        $parts         = array_map('ltrim', preg_split($pattern, $content));
        $context       = $parts[0];
        $stack_traces  = (isset($parts[1])) ? $parts[1] : null;

		if ($stack_traces && $format_version == "laravel55") {
			$stack_traces = Str::replaceLast('"}', '', rtrim($stack_traces));
		}

        return compact('context', 'stack_traces', 'format_version');
    }

	/**
	 * Parses the context part of the log entry into an array containing the necessary information
	 *
	 * @param  string $content
	 *
	 * @param string $format_version
	 *
	 * @return array Structure is ['message' => '', 'exception' => '', 'in' => '', 'line' => '']
	 */
    public function parseLogContext($content, $format_version = 'laravel53')
    {
        $content = trim($content);
		$content = preg_replace('/[\r\n]+/', ' ', $content);
		$content = preg_replace('/\s+/', ' ', $content);

		$inLinePattern = '/^' . self::CONTEXT_IN_PATTERN . '$/';

		if ($format_version == 'laravel55') {
			$exceptionAndMessagePattern = '/^' . self::CONTEXT_EXCEPTION_PATTERN_L55 . self::CONTEXT_MESSAGE_PATTERN . '$/';

			$context = [
				'exception' => null,
				'message' => null,
				'in' => null,
				'line' => null,
				'user' => null
			];

			$json = false;

			if (preg_match('/"userId"\:.+"exception"/', $content)) {
				try {
					$parts = preg_split('/("userId"\:.+"exception")/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

					$userData = explode('"exception"', $parts[1]);
					$userData = '{' . trim($userData[0], ',') . '}';
				    $userData = json_decode($userData, true);
					$userInfo = [];

					if (isset($userData['userId'])) {
						$userInfo[] = "User ID: " . $userData['userId'];
					}

					if (isset($userData['email'])) {
						$userInfo[] = "Email: " . $userData['email'];
					}

					$userInfo = implode(', ', $userInfo);
					$context['user'] = $userInfo;

					$json = '{"exception"' . $parts[2] . '"}';
				} catch (\Exception $e) {

				}
			} else {
				$parts = explode('{"exception"', $content);

				if (isset($parts[1])) {
					$json = '{"exception"' . $parts[1] . '"}';
				}
			}

			if ($json) {
				try {
					$exceptionsData = json_decode($json, true);
					$exceptionsData = $exceptionsData['exception'];

					if (Str::startsWith($exceptionsData, '[object]')) {
						$exceptionsData = Str::replaceFirst('[object]', '', $exceptionsData);
					}

					$exceptionsData = trim($exceptionsData);
					$exceptionsData = trim($exceptionsData, '()');

					$exceptionsAndMessages = explode(', ', $exceptionsData);
					$exceptionsAndMessages = array_reverse($exceptionsAndMessages);

					$exceptionsAndMessagesArray = [];

					foreach ($exceptionsAndMessages as $exceptionAndMessage) {

						$exceptionsAndMessagesArray[] = $this->parseLogData($exceptionAndMessage, ' at ', $exceptionAndMessagePattern, $inLinePattern);

					}

					if (count($exceptionsAndMessagesArray) > 1) {
						$context['exception'] = $exceptionsAndMessagesArray;
					} else {
						$context['exception'] = $exceptionsAndMessagesArray[0]['exception'];
						$context['message'] = $exceptionsAndMessagesArray[0]['message'];
						$context['in'] = $exceptionsAndMessagesArray[0]['in'];
						$context['line'] = $exceptionsAndMessagesArray[0]['line'];
					}

				} catch (\Exception $e) {
					// could not decode json
					$context['message'] = $parts[0];
				}
			}
		} else {
			$exceptionAndMessagePattern = '/^' . self::CONTEXT_EXCEPTION_PATTERN . self::CONTEXT_MESSAGE_PATTERN . '$/';

			$context = $this->parseLogData($content, ' in ', $exceptionAndMessagePattern, $inLinePattern);
		}

        return $context;
    }

	protected function parseLogData($exceptionAndMessage, $delimiter, $exceptionAndMessagePattern, $inLinePattern)
	{
		$exception = null;
		$message   = null;
		$in        = null;
		$line      = null;

		$parts = explode($delimiter, $exceptionAndMessage);
		$inLine = null;

		if (count($parts) > 2) {
			$inLine = array_pop($parts);
			$exceptionAndMessage = implode($delimiter, $parts);
		} else {
			$exceptionAndMessage = $parts[0];
			$inLine = $parts[1] ?? null;
		}

		preg_match($exceptionAndMessagePattern, trim($exceptionAndMessage), $matches);

		if (isset($matches[1])) {
			$exception = $matches[1];
			$message   = $matches[2] ?? null;

			if ($inLine) {
				preg_match($inLinePattern, trim($inLine), $matches);

				$in = $matches[1] ?? null;
				$line = $matches[2] ?? null;
			}
		} else {
			$message = $exceptionAndMessage;
		}

		return compact('message', 'exception', 'in', 'line');
	}

    /**
     * Parses the stack trace part of the log entry into an array containing the necessary information
     *
     * @param  string  $content
     *
     * @return array
     */
    public function parseStackTrace($content)
    {
        $content = trim($content);
        $pattern = '/^'.self::STACK_TRACE_INDEX_PATTERN.'/m';

        if (empty($content)) {
            return [];
        }

        $traces = preg_split($pattern, $content);

        if (empty($trace[0])) {
            array_shift($traces);
        }

        return $traces;
    }

    /**
     * Parses the content of the trace entry into an array containing the necessary information
     *
     * @param  string  $content
     *
     * @return array   Structure is ['caught_at' => '', 'in' => '', 'line' => '']
     */
    public function parseTraceEntry($content)
    {
        $content = trim($content);

        $caught_at = $content;
        $in = $line = null;

        if (!empty($content) && preg_match("/.*".self::TRACE_IN_DIVIDER_PATTERN.".*/", $content)) {
            $split = array_map('trim', preg_split("/".self::TRACE_IN_DIVIDER_PATTERN."/", $content));

            $in   = trim($split[0]);
            $caught_at = (isset($split[1])) ? $split[1] : null;

            if (preg_match("/^".self::TRACE_FILE_PATTERN."$/", $in, $matchs)) {
                $in   = trim($matchs[1]);
                $line = $matchs[2];
            }
        }

        return compact('caught_at', 'in', 'line');
    }
}
