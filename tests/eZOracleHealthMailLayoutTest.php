<?php
/**
 * The health mail of the Oracle cronjobs (eZOracleTools::finishCronjob()) is sent as text and as HTML in the kernel's
 * mail layout (design:mail/layout.tpl), as text only with a kernel without it. Needs the kernel (Exponential 6.0.15
 * or later), no database (no Oracle either); the mail goes to a transport of the test that keeps it, nothing is sent:
 *
 *   php vendor/bin/phpunit extension/ezoracle/tests/eZOracleHealthMailLayoutTest.php
 *
 *  HM-01 - a check that failed: the report text is the text part, the HTML part the layout with the subject
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tests
 */

require_once dirname( __DIR__ ) . '/classes/ezoracletools.php';

if ( class_exists( 'eZMailTransport' ) && !class_exists( 'eZOracleHealthMailLayoutTestTransport' ) )
{
    /** Keeps the mails it is given; never sends */
    class eZOracleHealthMailLayoutTestTransport extends eZMailTransport
    {
        public static $sent = array();

        function sendMail( eZMail $mail )
        {
            static::$sent[] = $mail;
            return true;
        }
    }
}

class eZOracleHealthMailLayoutTest extends PHPUnit\Framework\TestCase
{
    private $saved = array();
    private $savedReceivers = null;
    private $savedGlobals = array();

    protected function setUp(): void
    {
        if ( !class_exists( 'expMailTemplateBody' ) || !method_exists( 'expMailTemplateBody', 'fromText' ) )
            $this->markTestSkipped( 'needs the kernel with the mail layout (Exponential 6.0.15 or later)' );
        foreach ( array( 'eZTemplateCompilerSettings', 'eZSiteBasics', 'eZCurrentAccess' ) as $name )
            $this->savedGlobals[$name] = array_key_exists( $name, $GLOBALS ) ? array( $GLOBALS[$name] ) : null;
        $GLOBALS['eZTemplateCompilerSettings']['compile'] = false;
        $GLOBALS['eZSiteBasics']['no-cache-adviced'] = true;
        if ( !isset( $GLOBALS['eZCurrentAccess']['name'] ) )
            $GLOBALS['eZCurrentAccess'] = array( 'name' => eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ),
                                                 'type' => eZSiteAccess::TYPE_DEFAULT );
        $ini = eZINI::instance();
        foreach ( array( 'Transport', 'TransportAlias' ) as $name )
            $this->saved[$name] = $ini->hasVariable( 'MailSettings', $name ) ? $ini->variable( 'MailSettings', $name ) : null;
        $ini->setVariable( 'MailSettings', 'Transport', 'ezoraclerecord' );
        $ini->setVariable( 'MailSettings', 'TransportAlias', array( 'ezoraclerecord' => 'eZOracleHealthMailLayoutTestTransport' ) );
        $oracle = eZINI::instance( 'ezoracle.ini' );
        $this->savedReceivers = $oracle->hasVariable( 'CronjobSettings', 'HealthMailReceivers' ) ? array( $oracle->variable( 'CronjobSettings', 'HealthMailReceivers' ) ) : null;
        $oracle->setVariable( 'CronjobSettings', 'HealthMailReceivers', array( 'dba@example.invalid' ) );
        if ( class_exists( 'expMailGate' ) )
            expMailGate::setLogFileForTest( eZSys::rootDir() . '/var/tmp/ezoracle-health-mailgate-' . getmypid() . '.jsonl' );
        eZOracleHealthMailLayoutTestTransport::$sent = array();
    }

    protected function tearDown(): void
    {
        $ini = eZINI::instance();
        foreach ( $this->saved as $name => $value )
        {
            if ( $value === null )
                $ini->removeSetting( 'MailSettings', $name );
            else
                $ini->setVariable( 'MailSettings', $name, $value );
        }
        $oracle = eZINI::instance( 'ezoracle.ini' );
        if ( $this->savedReceivers === null )
            $oracle->removeSetting( 'CronjobSettings', 'HealthMailReceivers' );
        else
            $oracle->setVariable( 'CronjobSettings', 'HealthMailReceivers', $this->savedReceivers[0] );
        if ( class_exists( 'expMailGate' ) )
        {
            expMailGate::setLogFileForTest( null );
            $log = eZSys::rootDir() . '/var/tmp/ezoracle-health-mailgate-' . getmypid() . '.jsonl';
            if ( is_file( $log ) )
                unlink( $log );
        }
        foreach ( $this->savedGlobals as $name => $saved )
        {
            if ( $saved === null )
                unset( $GLOBALS[$name] );
            else
                $GLOBALS[$name] = $saved[0];
        }
    }

    /** HM-01 */
    public function testHealthMailIsTextAndHtml()
    {
        // any object stands for the database: finishCronjob() does not use it
        $tools = new eZOracleTools( new stdClass() );
        $tools->line( 'PASS', 'connection' );
        $tools->line( 'FAIL', 'tablespace <USERS> 98% full' );
        $handler = set_exception_handler( null );
        restore_exception_handler();
        try
        {
            $tools->finishCronjob( 'health check', true );
        }
        finally
        {
            $current = set_exception_handler( null );
            restore_exception_handler();
            if ( $current !== $handler )
                restore_exception_handler();
        }
        $this->assertCount( 1, eZOracleHealthMailLayoutTestTransport::$sent );
        $mail = eZOracleHealthMailLayoutTestTransport::$sent[0];
        $this->assertStringStartsWith( '[FAIL] Oracle health check of ', $mail->subject() );
        $this->assertSame( 'multipart/alternative', $mail->contentType() );
        $this->assertStringContainsString( "FAIL tablespace <USERS> 98% full\n", $mail->AlternativeParts['text'] );
        $this->assertStringContainsString( 'role="presentation"', $mail->AlternativeParts['html'] );
        $this->assertStringContainsString( 'PASS connection<br />FAIL tablespace &lt;USERS&gt; 98% full', $mail->AlternativeParts['html'] );
    }
}
