<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('WP_CACHE', true);
define( 'WPCACHEHOME', '/home/html/emigrationmuseum.cz/public_html/new/wp-content/plugins/wp-super-cache/' );
define( 'DB_NAME', 'emigrationmuseum' );

/** Database username */
define( 'DB_USER', 'emigrationmuseum' );

/** Database password */
define( 'DB_PASSWORD', 'NAmr8rUN' );

/** Database hostname */
define( 'DB_HOST', 'db.db029.webglobe.com' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          '{Nt&Fz{yJMr)P}Nr^|qj=!BS(6M9]$i>0L52Y{PH@b%p_SM_6.=H6.(}%]1bJVb~' );
define( 'SECURE_AUTH_KEY',   'f}p5Cf.lXKx+PypGX#~[<^25zqbsAtD6]Pq?n7N^0=Yac%y_5&.DFd^.W2~.`fcy' );
define( 'LOGGED_IN_KEY',     'sHpZP4gG=mNgEMAE10oYJKOsB!=;EydKST. lE5Cy%:Ky[r,uG0_YQUD@$nY1E8a' );
define( 'NONCE_KEY',         'gjJG0[XE$Y XxK$C];?hSY{_XCK_1I+A;N9Nc$QuQWUR3y3^rW->1bGC8n5ia%H7' );
define( 'AUTH_SALT',         ')l:d3A#4,[?^hiZFYw0|p-T8N`V.L;ZPGUKjXc>1XulMs]Mk7p*F1N5f.7As;tk)' );
define( 'SECURE_AUTH_SALT',  'O_|9!`5.mf;q+? bfO<[cA1d2I$I@mg9KwvBHyCb+C&sg}q6Y:%T.ZRSci}}XHa/' );
define( 'LOGGED_IN_SALT',    'pWd7n3_(4aYRl$A5W>P{3NCUI:#=KO;=lmDcFFW`{rySjrH8Sut{Sf~ThGCRjEXE' );
define( 'NONCE_SALT',        'D,MG!gkg6>^wf;?C&h=YnL7@l[w4nt?~Jiju%t}i{ws&br@ITbN.&%,.vS:;d`eE' );
define( 'WP_CACHE_KEY_SALT', ') 8N).dd5OZroigSTQTp@H;_Lqq=9(S!9eG09dANGp5{`o>OSJs(B2RY|D1-! Dy' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

define( 'WP_AUTO_UPDATE_CORE', true );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
