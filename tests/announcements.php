<?php
// Announcements: form cleaning and the public list (dates, switch).
require_once __DIR__ . '/../lib/announcements.php';
use FLXUpdate\Announcements;
function same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
	}
}
same( Announcements::clean( array( 'body_en' => 'x' ) ), null, 'Title required' );
$a = Announcements::clean( array( 'title_tr' => ' Merhaba ', 'url' => 'javascript:alert(1)', 'tone' => 'bad', 'on' => '1', 'plugins' => 'flx-donate, FLX-chat,../x', 'starts' => '2026-11-20T10:00', 'ends' => '2026-11-21T10:00' ), array(), 1000 );
same( array( $a['title']['tr'], $a['url'], $a['tone'], $a['plugins'] ), array( 'Merhaba', '', 'info', array( 'flx-donate', 'flx-chat' ) ), 'Clean fields' );
same( $a['starts'], gmmktime( 10, 0, 0, 11, 20, 2026 ), 'Start is UTC' );
same( Announcements::clean( array( 'title_en' => 'T', 'url' => 'https://fluxwp.com/x' ) )['url'], 'https://fluxwp.com/x', 'https link kept' );
$all = array( $a, array( 'id' => 'b', 'on' => false ) );
same( count( Announcements::public_list( $all, $a['starts'] - 1 ) ), 0, 'Before start' );
same( count( Announcements::public_list( $all, $a['starts'] + 5 ) ), 1, 'Live' );
same( count( Announcements::public_list( $all, $a['ends'] ) ), 0, 'After end' );
echo "ok\n";
