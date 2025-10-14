<?php

if (! function_exists('mpesa')) {
    function mpesa()
    {
        return new \Lyre\Billing\Services\Mpesa\Client();
    }
}
