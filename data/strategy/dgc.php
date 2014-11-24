<?php
return array(
    'test' => array(
        'desc' => 'this is a test',
        'frequency' => array(6, 20),
        'pattern' => '/test/i',
        'alerts' => array(13788993215, 'jofu@vip.qq.com'),
    ),
    'user_login' => array(
        'desc' => 'user login is required',
        'frequency' => array(3, 5),
        'pattern' => '/^UID=0/i',
        'alerts' => array(13788993215, 'jofu@vip.qq.com'),
    ),
    'hfb_paypassword' => array(
        'desc' => '用户支付密码错误',
        'frequency' => array(2, 5),
        'pattern' => '/vendor_hfb_member-checkPayPwd/i',
        'alerts' => array(13788993215, 13816246686),
    ),
);