<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Mysqli;


class Utility
{
    public static function writeSql(&$output, ?string $data)
    {
        if (is_resource($output)) {
            fwrite($output, $data);
        } else {
            $output .= $data;
        }
    }
}