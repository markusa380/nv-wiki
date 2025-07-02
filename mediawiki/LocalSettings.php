<?php
# See https://www.mediawiki.org/wiki/Manual:Configuration_settings

# Protect against web entry
if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

$wgSitename = "Night Vision Wiki";
$wgMetaNamespace = "Project";

## The URL base path to the directory containing the wiki;
## defaults for all runtime URL paths are based off of this.
## For more information on customizing the URLs
## (like /w/index.php/Page_title to /wiki/Page_title) please see:
## https://www.mediawiki.org/wiki/Manual:Short_URL
$wgScriptPath = "";

## The protocol and server name to use in fully-qualified URLs
# $wgServer = "http://192.168.0.159:8082";
$wgServer = "https://nv-intl.com";

## The URL path to static resources (images, scripts, etc.)
$wgResourceBasePath = $wgScriptPath;

## The URL paths to the logo.  Make sure you change this from the default,
## or else you'll overwrite your logo when you upgrade!
$wgLogos = [ '1x' => "$wgResourceBasePath/nv_wiki.png" ];
$wgAppleTouchIcon = "/apple-touch-icon.png";

$wgEnableEmail = false;
$wgEnableUserEmail = false;

## Unused
$wgEmergencyContact = "info@nv-intl.com";
$wgPasswordSender = "info@nv-intl.com";

$wgEnotifUserTalk = false;
$wgEnotifWatchlist = false;
$wgEmailAuthentication = false;

## Database settings
$wgDBtype = "mysql";
$wgDBserver = "database";
$wgDBname = "wikidb";
$wgDBuser = "wiki";
$wgDBpassword = file_get_contents( '/run/secrets/wiki-password' );

# MySQL specific settings
$wgDBprefix = "";

# MySQL table options to use during installation or update
$wgDBTableOptions = "ENGINE=InnoDB, DEFAULT CHARSET=binary";

# Shared database table
# This has no effect unless $wgSharedDB is also set.
$wgSharedTables[] = "actor";

## Shared memory settings
$wgMainCacheType = CACHE_NONE;
$wgMemCachedServers = [];

## To enable image uploads, make sure the 'images' directory
## is writable, then set this to true:
$wgEnableUploads = true;
$wgUseImageMagick = true;
$wgImageMagickConvertCommand = "/usr/bin/convert";
$wgFileExtensions[] = 'svg';
$wgFileExtensions[] = 'pdf';
$wgSVGConverter = 'ImageMagick';

# To avoid https://www.mediawiki.org/wiki/Manual:Common_errors_and_symptoms#Error_creating_thumbnail:_File_with_dimensions_greater_than_12.5_MP
$wgMaxImageArea = 125000000;
$wgThumbnailEpoch = 20250629180012;

# InstantCommons allows wiki to use images from https://commons.wikimedia.org
$wgUseInstantCommons = false;

# Periodically send a pingback to https://www.mediawiki.org/ with basic data
# about this MediaWiki instance. The Wikimedia Foundation shares this data
# with MediaWiki developers to help guide future development efforts.
$wgPingback = true;

# Site language code, should be one of the list in ./includes/languages/data/Names.php
$wgLanguageCode = "en";

# Time zone
$wgLocaltimezone = "UTC";

## If you use ImageMagick (or any other shell command) on a
## Linux server, this will need to be set to the name of an
## available UTF-8 locale. This should ideally be set to an English
## language locale so that the behaviour of C library functions will
## be consistent with typical installations. Use $wgLanguageCode to
## localise the wiki.
$wgShellLocale = "C.UTF-8";

## Set $wgCacheDirectory to a writable directory on the web server
## to make your wiki go slightly faster. The directory should not
## be publicly accessible from the web.
#$wgCacheDirectory = "$IP/cache";

$wgSecretKey = file_get_contents( '/run/secrets/wg-secret' );

# Changing this will log out all existing sessions.
$wgAuthenticationTokenVersion = "1";

# Site upgrade key. Must be set to a string (default provided) to turn on the
# web installer while LocalSettings.php is in place
$wgUpgradeKey = "b64d16f64be030ce";

## For attaching licensing metadata to pages, and displaying an
## appropriate copyright notice / icon. GNU Free Documentation
## License and Creative Commons licenses are supported so far.
$wgRightsPage = ""; # Set to the title of a wiki page that describes your license/copyright
$wgRightsUrl = "";
$wgRightsText = "";
$wgRightsIcon = "";

# Path to the GNU diff3 utility. Used for conflict resolution.
$wgDiff3 = "/usr/bin/diff3";

## Default skin: you can change the default skin. Use the internal symbolic
## names, e.g. 'vector' or 'monobook':
$wgDefaultSkin = "timeless";

# Enabled skins.
# The following skins were automatically enabled:
wfLoadSkin( 'Vector' );
wfLoadSkin( 'Timeless' );


# Enabled extensions.

# Enabled extensions. Most of the extensions are enabled by adding
# wfLoadExtension( 'ExtensionName' );
# to LocalSettings.php. Check specific extension documentation for more details.
# The following extensions were automatically enabled:
wfLoadExtension( 'CategoryTree' );
wfLoadExtension( 'ConfirmEdit' );
wfLoadExtension( 'VisualEditor' );
wfLoadExtension( 'Cite' );
wfLoadExtension( 'NativeSvgHandler' );
wfLoadExtension( 'SemanticMediaWiki' );
wfLoadExtension( 'TemplateStyles' );
wfLoadExtension( 'ParserFunctions' );
wfLoadExtension( 'HitCounters' );
wfLoadExtension( 'UploadWizard' );
wfLoadExtension( 'TemplateData' );
wfLoadExtension( 'AWS' );
wfLoadExtension( 'Math' );
wfLoadExtension( 'ContributionScores' );
wfLoadExtension( 'PdfHandler' );
wfLoadExtension( 'Nuke' );

enableSemantics( 'nv-intl.com' );
$smwgConfigFileDir = "/data/smw-config";

$awsCredentialsFileContent = file_get_contents( '/run/secrets/aws-credentials' );

$wgAWSCredentials = [
  'key' => preg_match('/aws_access_key_id\s*=\s*(\S+)/', $awsCredentialsFileContent, $matches) ? $matches[1] : '',
  'secret' => preg_match('/aws_secret_access_key\s*=\s*(\S+)/', $awsCredentialsFileContent, $matches) ? $matches[1] : '',
  'token' => false
];
$wgAWSRegion = 'eu-central-1';
$wgAWSBucketName = 'night-vision-wiki';
$wgAWSRepoHashLevels = '2';
$wgAWSRepoDeletedHashLevels = '3';

$wgPFEnableStringFunctions = true;
$wgApiFrameOptions = 'SAMEORIGIN';

# Replace default upload page with UploadWizard
$wgExtensionFunctions[] = function() {
        $GLOBALS['wgUploadNavigationUrl'] = SpecialPage::getTitleFor( 'UploadWizard' )->getLocalURL();
        return true;
};

$wgUploadWizardConfig = [
        'altUploadForm' => 'Special:Upload',
        'feedbackLink' => false,
        'alternativeUploadToolsPage' => false,
];

$wgUseInstantCommons = true;
$wgUploadNavigationUrl = '/wiki/index.php/Special:UploadWizard';

# End of automatically generated settings.
# Add more configuration options below.


$wgCiteVisualEditorOtherGroup = true;

// Exclude Bots from the reporting - Can be omitted.
$wgContribScoreIgnoreBots = true;
// Exclude Blocked Users from the reporting - Can be omitted.
$wgContribScoreIgnoreBlockedUsers = true;
// Exclude specific usernames from the reporting - Can be omitted.
$wgContribScoreIgnoreUsernames = [];
// Use real user names when available - Can be omitted. Only for MediaWiki 1.19 and later.
$wgContribScoresUseRealName = false;
// Set to true to disable cache for parser function and inclusion of table.
$wgContribScoreDisableCache = false;
// Use the total edit count to compute the Contribution score.
$wgContribScoreUseRoughEditCount = false;
// Each array defines a report - 7,50 is "past 7 days" and "LIMIT 50" - Can be omitted.
$wgContribScoreReports = [
    [ 7, 50 ],
    [ 0, 500 ]
];

$wgFeedDiffCutoff = 15;

wfLoadExtension( 'PluggableAuth' );
wfLoadExtension( 'WSOAuth' );
wfLoadExtension( 'DiscordAuth' );

$wgOAuthCustomAuthProviders = ['discord' => \DiscordAuth\AuthenticationProvider\DiscordAuth::class];
$wgPluggableAuth_EnableLocalLogin = true;
$wgPluggableAuth_Config['discordauth'] = [
    'plugin' => 'WSOAuth',
    'data' => [
        'type' => 'discord',
        'uri' => 'https://discord.com/oauth2/authorize',
        'clientId' => '1335193827018932316',
        'clientSecret' => file_get_contents( '/run/secrets/discord-auth-client-secret' ),
        'redirectUri' => 'https://nv-intl.com/index.php?title=Special:PluggableAuthLogin'
    ],
    'buttonLabelMessage' => 'discordauth-login-button-label'
];

$wgDiscordAuthBotToken = file_get_contents( '/run/secrets/discord-auth-bot-token' );
$wgDiscordGuildId = 808018607086108703; // you can copy this within Discord app interface
$wgDiscordApprovedRoles = ['contributor']; // users only with the specified roles will be able to login
$wgDiscordAllowAllUsers = true;

$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['*']['createaccount'] = false;
$wgGroupPermissions['*']['autocreateaccount'] = true; 
