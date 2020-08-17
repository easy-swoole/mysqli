<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Mysqli;


class Utility
{
    public static function writeSql(&$output, string $data)
    {
        if (is_resource($output)) {
            fwrite($output, $data);
        } else {
            $output .= $data;
        }
    }

    public static function progressBar(int $current, int $total)
    {
        printf("[%-100s] %d%% \r", str_repeat('=', $current / $total * 100) . '>', $current / $total * 100);
    }
}