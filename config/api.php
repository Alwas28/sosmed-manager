<?php

return [
    /*
     |--------------------------------------------------------------------------
     | API Key untuk aplikasi Kendari Info
     |--------------------------------------------------------------------------
     |
     | Kunci ini dikirim Flutter melalui header X-API-Key. Simpan nilai aslinya
     | di .env server sebagai KENDARI_API_KEY. Tidak ada perubahan database.
     |
     */

    'key' => env('KENDARI_API_KEY'),

    'header' => env('KENDARI_API_KEY_HEADER', 'X-API-Key'),
];
