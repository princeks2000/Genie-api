<?php
$get = [];
$post = [];
$public = [];
$public[] = ['login', 'user/login'];
$public[] = ['verify_login_otp', 'user/verify_login_otp'];
$public[] = ['verify_login_totp', 'user/verify_login_totp'];
$public[] = ['forgot_password_request', 'user/forgot_password_request'];
$public[] = ['reset_password_with_otp', 'user/reset_password_with_otp'];
$publicget[] = ['cron/{task}/{key}', 'cron/task'];

$post[] = ['enable_2fa_init', 'user/enable_2fa_init'];
$post[] = ['enable_2fa_verify', 'user/enable_2fa_verify'];
$post[] = ['disable_2fa', 'user/disable_2fa'];
$post[] = ['update_profile', 'user/update_profile'];
$get[] = ['switchuserlist', 'user/switchuserlist'];
$post[] = ['impersonate', 'user/impersonate_user'];
$get[] = ['profile', 'user/getprofile'];
$post[] = ['admin_list_routes', 'admin/admin_list_routes'];
$get[] = ['admin_user_levels', 'admin/user_levels'];
$get[] = ['admin_list_users', 'admin/list_users'];
$post[] = ['admin_set_level_permissions', 'admin/admin_set_level_permissions'];
$post[] = ['admin_get_level_permissions', 'admin/admin_get_level_permissions'];
$post[] = ['admin_create_user', 'admin/create_user'];
$post[] = ['admin_update_user', 'admin/update_user'];
$post[] = ['admin_delete_user', 'admin/delete_user'];
$post[] = ['admin_set_all_level_permissions', 'admin/admin_set_all_level_permissions'];

$post[] = ['admin_create_user_level', 'admin/create_user_level'];
$post[] = ['admin_update_user_level', 'admin/update_user_level'];
$post[] = ['admin_delete_user_level', 'admin/delete_user_level'];

$post[] = ['credentials_retrieve', 'settings/credentials_retrieve'];
$post[] = ['credentials_save', 'settings/credentials_save'];
$post[] = ['credentials_delete', 'settings/credentials_delete'];
$post[] = ['credentials_verify', 'settings/verify_platform_credentials'];

$post[] = ['settings_retrieve', 'settings/settings_retrieve'];
$post[] = ['settings_save', 'settings/settings_save'];
$post[] = ['settings_delete', 'settings/settings_delete'];

// Configurations (JSON value store)
$post[] = ['configurations/setup', 'settings/configurations_setup'];
$post[] = ['configurations/retrieve', 'settings/configurations_retrieve'];
$post[] = ['configurations/save', 'settings/configurations_save'];
$post[] = ['configurations/delete', 'settings/configurations_delete'];

$get[] = ['logo_types/list', 'settings/logo_types_list'];
$post[] = ['logo_types/toggle', 'settings/logo_types_toggle'];

$post[] = ['explore_api_keys', 'settings/explore_api_keys'];
$post[] = ['create_display_field', 'settings/save_display_fields'];
$post[] = ['update_display_field', 'settings/save_display_fields'];
$post[] = ['delete_display_field', 'settings/delete_display_fields'];
$post[] = ['retrieve_display_field', 'settings/retrieve_display_fields'];


// Customer sync endpoints
$post[] = ['customer/save_platform_response', 'customer/save_platform_response'];
$post[] = ['customer/update_platform_response', 'customer/update_platform_response'];


// Customer CRUD endpoints
$post[] = ['customer/save', 'customer/customer_save'];
$post[] = ['customer/retrieve', 'customer/customer_retrieve'];
$post[] = ['customer/list', 'customer/customer_list'];
$post[] = ['customer/delete', 'customer/customer_delete'];
$post[] = ['customer/materialize_all', 'customer/materialize_all_display_fields'];
$get[] = ['customer/next_id', 'customer/get_next_customer_id'];

// Logo upload endpoint
$post[] = ['customer/upload_logo', 'logo/upload_logo'];
// Logo update endpoint (updates files and metadata by logo_id)
$post[] = ['customer/update_logo', 'logo/update_logo'];
// Thread management
$post[] = ['customer/save_default_threads', 'logo/save_default_threads'];
$post[] = ['customer/preview_recolor', 'logo/preview_recolor'];
$get[] = ['customer/get_logo', 'logo/get_logo'];
$get[] = ['customer/get_logos', 'logo/get_logos'];
$post[] = ['customer/apply_default_threads', 'logo/apply_default_threads'];
// Setup endpoint for logo conversion (creates tables/settings)
$post[] = ['customer/setup_logo_conversion', 'customer/setup_logo_conversion'];

// Color palette endpoints
$post[] = ['customer/color_palette/create', 'logo/color_palette_create'];
$post[] = ['customer/color_palette/update', 'logo/color_palette_update'];
$post[] = ['customer/color_palette/delete', 'logo/color_palette_delete'];
$get[] = ['customer/color_palette/list', 'logo/color_palette_list'];

// Thread grouping endpoints
$get[] = ['customer/thread_grouping/get', 'logo/get_thread_grouping'];
$post[] = ['customer/thread_grouping/update', 'logo/update_thread_grouping'];

// Logo trash endpoints
$post[] = ['customer/logo/trash', 'logo/trash_logo'];
$get[] = ['customer/trash_logos', 'logo/get_trashed_logos'];
$post[] = ['customer/logo/permanently_delete', 'logo/permanently_delete_logo'];
$get[] = ['customer/logo/downloadcolorcard', 'logo/downloadcolorcard'];
$get[] = ['customer/logo/download_zip', 'logo/download_logo_zip'];

// Platform management
$get[] = ['list_platforms', 'settings/list_platforms'];
$post[] = ['update_platform', 'settings/update_platform'];

// Cron management
$get[] = ['list_schedulers', 'settings/list_schedulers'];
$post[] = ['update_scheduler', 'settings/update_scheduler'];

$get[] = ['settings/color_list', 'settings/color_list'];
$get[] = ['settings/color_manufacturer', 'settings/color_manufacturer'];

// Color CRUD endpoints
$post[] = ['settings/color_manufacturer/save', 'settings/color_manufacturer_save'];
$post[] = ['settings/color_manufacturer/delete', 'settings/color_manufacturer_delete'];
$post[] = ['settings/color_list/retrieve', 'settings/color_list_retrieve'];
$post[] = ['settings/color_list/save', 'settings/color_list_save'];
$post[] = ['settings/color_list/delete', 'settings/color_list_delete'];

// Dict Color CRUD endpoints
$get[] = ['settings/dist_color', 'settings/dict_color_list'];
$post[] = ['settings/dist_color/retrieve', 'settings/dict_color_retrieve'];
$post[] = ['settings/dist_color/save', 'settings/dict_color_save'];
$post[] = ['settings/dist_color/delete', 'settings/dict_color_delete'];

// Dict Placement CRUD endpoints
$get[] = ['settings/dist_placement', 'settings/dict_placement_list'];
$post[] = ['settings/dist_placement/retrieve', 'settings/dict_placement_retrieve'];
$post[] = ['settings/dist_placement/save', 'settings/dict_placement_save'];
$post[] = ['settings/dist_placement/delete', 'settings/dict_placement_delete'];

// Size Ranges CRUD endpoints
$get[] = ['size_ranges/list', 'size_ranges/list_size_ranges'];
$get[] = ['size_ranges/get', 'size_ranges/get_size_range'];
$post[] = ['size_ranges/create', 'size_ranges/create_size_range'];
$post[] = ['size_ranges/update', 'size_ranges/update_size_range'];
$post[] = ['size_ranges/delete', 'size_ranges/delete_size_range'];
// Product sync endpoints
$post[] = ['product/save_platform_response', 'product/save_platform_response'];
$post[] = ['product/materialize_all', 'product/materialize_all_display_fields'];

// Product CRUD endpoints
$post[] = ['product/save', 'product/product_save'];
$post[] = ['product/retrieve', 'product/product_retrieve'];
$post[] = ['product/list', 'product/product_list'];
$post[] = ['product/delete', 'product/product_delete'];

// General CRUD endpoints
$post[] = ['general/feature_request/save', 'general/feature_request_save'];
$get[] = ['general/feature_request/list', 'general/feature_request_list'];
$post[] = ['general/feature_request/retrieve', 'general/feature_request_retrieve'];
$post[] = ['general/feature_request/delete', 'general/feature_request_delete'];
