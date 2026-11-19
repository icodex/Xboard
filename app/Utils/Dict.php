<?php

namespace App\Utils;

class Dict
{
    CONST EMAIL_WHITELIST_SUFFIX_DEFAULT = [
        'gmail.com',
        'qq.com',
        '163.com',
        'yahoo.com',
        'sina.com',
        '126.com',
        'outlook.com',
        'yeah.net',
        'foxmail.com'
    ];
    CONST WITHDRAW_METHOD_WHITELIST_DEFAULT = [
        '支付宝',
        'USDT',
        'Paypal'
    ];

    // 统计明细保留月数：reset:log 按此删除，管理端查询区间按此收敛（两边必须一致）
    CONST STAT_RETENTION_MONTHS = 2;
}
