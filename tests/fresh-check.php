<?php
// /v1/check fresh=1: release list is revalidated (ETags kept), at most once per FRESH_MIN_AGE.
require_once __DIR__ . '/../lib/server.php';
use FLXUpdate\Server;
function same( $a, $e, string $m ): void { if ( $a !== $e ) { throw new RuntimeException( $m . ': ' . var_export( $a, true ) ); } }
$dir = sys_get_temp_dir() . '/flx-fresh-' . bin2hex( random_bytes( 4 ) ); mkdir( $dir );
$ref = new ReflectionClass( Server::class );
$s   = $ref->newInstanceWithoutConstructor();
$set = function ( string $name, $value ) use ( $ref, $s ) { $p = $ref->getProperty( $name ); $p->setAccessible( true ); $p->setValue( $s, $value ); };
$set( 'data_dir', $dir ); $set( 'config', array( 'cache_ttl' => 300, 'repo' => 'x/y', 'public_repos' => array(), 'private_repos' => array() ) );
$repos = $ref->getMethod( 'repos' ); $repos->setAccessible( true ); $sig = md5( json_encode( $repos->invoke( $s ) ) );
$file = $dir . '/releases.json';
$write = function ( array $c ) use ( $file ) { file_put_contents( $file, json_encode( $c ) ); };
$read  = function () use ( $file ) { return json_decode( (string) file_get_contents( $file ), true ); };
$base  = array( 'sig' => $sig, 'items' => array( array( 'slug' => 'a' ) ), 'etags' => array( 'x/y' => '"e1"' ) );

$write( $base + array( 'at' => time() - 5 ) );
$s->revalidate_releases( 15 );
same( $read()['at'] > 0, true, 'Younger than 15 s stays fresh' );

$write( $base + array( 'at' => time() - 60 ) );
$s->revalidate_releases( 15 );
$c = $read();
same( array( $c['at'], $c['etags'] ), array( 0, array( 'x/y' => '"e1"' ) ), 'Older list expires, ETags kept' );

$write( $base + array( 'at' => time() - 60, 'fail_until' => time() + 20 ) );
$s->revalidate_releases( 15 );
same( $read()['at'] > 0, true, 'Backing-off list is left alone' );

$write( array( 'at' => time() - 60, 'sig' => 'other', 'items' => array( array( 'slug' => 'a' ) ) ) );
$s->revalidate_releases( 15 );
same( $read()['at'] > 0, true, 'Other repo signature left alone' );
echo "Fresh check passed.\n";
