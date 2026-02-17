<?php
define("BASEPATH", "https://localhost/genie-api/");
define("JWT_SECRET", 'YOUR_JWT_SECRET_HERE');
define("DBHOST", "localhost");
define("DBNAME", "genie");
define("DBUSER", "root");
define("DBPASS", "");
define("JWT_LOG", "off");
define("API_LOG", "on");
define("PUBLIC_ROUTES", [
    'user/login',
    'user/verify_login_otp',
    'user/forgot_password_request',
    'user/reset_password_with_otp',
    'user/verify_login_totp'
]);
