<?php

return [
    'base_url' => env('FREEPBX_BASE_URL', 'http://192.168.2.250:83'),
    // كان هون FREEPBX_AUTH_URL — اسم مختلف عن المتغير الفعلي المستخدم بكل
    // كنترولرات PBX التانية (FREEPBX_TOKEN_URL)، فكانت هاي القيمة ترجع فاضية دايمًا
    'auth_url' => env('FREEPBX_TOKEN_URL', null),
    'graphql_url' => env('FREEPBX_GRAPHQL_URL', null),
    'rest_url' => env('FREEPBX_REST_URL', null),
    'client_id' => env('FREEPBX_CLIENT_ID', ''),
    'client_secret' => env('FREEPBX_CLIENT_SECRET', ''),
    // السيرفر بيرفض scope=api صراحة ("invalid_scope") — الصلاحيات (gql/rest)
    // مثبّتة على مستوى الـclient نفسه بجهة FreePBX، فمنسيب الحقل فاضي افتراضيًا
    'scope' => env('FREEPBX_SCOPE', ''),
    'grant_type' => env('FREEPBX_GRANT_TYPE', 'client_credentials'),
];
