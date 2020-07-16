<?php

namespace AndrewBreksa\RSMQ;

/**
 * Class Util
 *
 * @package AndrewBreksa\RSMQ
 */
class Util
{
    /**
     * @param  int $length
     * @return string
     */
    public function makeID(int $length): string
    {
        $text = '';
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

        for ($i = 0; $i < $length; $i++) {
            $text .= $chars[rand(0, strlen($chars) - 1)];
        }

        return $text;
    }

    /**
     * @param  int $num
     * @param  int $count
     * @return string
     */
    public function formatZeroPad(int $num, int $count): string
    {
        $numStr = (string)(pow(10, $count) + $num);
        return substr($numStr, 1);
    }
}
