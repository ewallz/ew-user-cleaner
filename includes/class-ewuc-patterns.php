<?php
/**
 * Ready made pattern library for the Help tab.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Provides copyable example patterns with verified examples.
 */
class EWUC_Patterns {

	/**
	 * Username pattern recipes.
	 *
	 * Every pattern is automatically anchored to the whole value, so it must
	 * describe the entire username, not a fragment of it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function login_patterns(): array {
		return array(
			array(
				'title'   => __( 'Name followed by many digits', 'ew-user-cleaner' ),
				'pattern' => '[a-z]{2,12}[0-9]{5,}',
				'why'     => __( 'Bulk signup scripts often append a long number to a short name.', 'ew-user-cleaner' ),
				'matches' => array( 'mary123456', 'john9998877' ),
				'ignores' => array( 'mary12', 'marysmith', 'mary123456x' ),
			),
			array(
				'title'   => __( 'Digits only', 'ew-user-cleaner' ),
				'pattern' => '[0-9]{6,}',
				'why'     => __( 'Usernames that are nothing but a long number, such as phone based signups.', 'ew-user-cleaner' ),
				'matches' => array( '0123456789', '987654321' ),
				'ignores' => array( '12345', 'user123456' ),
			),
			array(
				'title'   => __( 'Random letter soup', 'ew-user-cleaner' ),
				'pattern' => '[bcdfghjklmnpqrstvwxyz]{7,}',
				'why'     => __( 'Machine generated names rarely contain vowels. Keep the weight low, some real names match.', 'ew-user-cleaner' ),
				'matches' => array( 'xkcdfghjkl', 'qwrtzpsdfg' ),
				'ignores' => array( 'jonathan', 'smith' ),
			),
			array(
				'title'   => __( 'Long hexadecimal or hash style name', 'ew-user-cleaner' ),
				'pattern' => '[a-f0-9]{16,}',
				'why'     => __( 'Names copied from a generated token or hash.', 'ew-user-cleaner' ),
				'matches' => array( 'a3f9c1d8b7e40021' ),
				'ignores' => array( 'abcdef', 'deadbeef' ),
			),
			array(
				'title'   => __( 'Repeated character runs', 'ew-user-cleaner' ),
				'pattern' => '.*(?:aaaa|bbbb|cccc|1111|0000|xxxx).*',
				'why'     => __( 'Keyboard mashing and padding characters.', 'ew-user-cleaner' ),
				'matches' => array( 'joaaaanne', 'test1111' ),
				'ignores' => array( 'joanne', 'test11' ),
			),
			array(
				'title'   => __( 'Marketing or throwaway keywords', 'ew-user-cleaner' ),
				'pattern' => '.*(?:casino|crypto|escort|viagra|betting|payday).*',
				'why'     => __( 'Spam registrations advertising a niche. Short words are risky: "seo" also matches "seoul", so prefer longer keywords and keep the weight low.', 'ew-user-cleaner' ),
				'matches' => array( 'bestcasino24', 'crypto_king' ),
				'ignores' => array( 'joanne', 'seoul_kim' ),
			),
			array(
				'title'   => __( 'Cyrillic or non Latin script', 'ew-user-cleaner' ),
				'pattern' => '.*[\x{0400}-\x{04FF}].*',
				'why'     => __( 'Useful only if your audience never uses these scripts. Do not enable on multilingual sites.', 'ew-user-cleaner' ),
				'matches' => array( 'иванов' ),
				'ignores' => array( 'ivanov' ),
			),
			array(
				'title'   => __( 'Name plus year suffix', 'ew-user-cleaner' ),
				'pattern' => '[a-z]{2,12}(?:19|20)[0-9]{2}',
				'why'     => __( 'Very common in generated accounts, but also in real ones. Use a low weight.', 'ew-user-cleaner' ),
				'matches' => array( 'peter1988', 'anna2024' ),
				'ignores' => array( 'peter88', 'peter' ),
			),
			array(
				'title'   => __( 'Two words joined by digits', 'ew-user-cleaner' ),
				'pattern' => '[a-z]{3,10}[0-9]{2,6}[a-z]{3,10}',
				'why'     => __( 'A signature of template based name generators.', 'ew-user-cleaner' ),
				'matches' => array( 'blue42sky', 'fast2024shop' ),
				'ignores' => array( 'bluesky', 'blue42' ),
			),
			array(
				'title'   => __( 'Very long single token', 'ew-user-cleaner' ),
				'pattern' => '[a-z0-9]{24,}',
				'why'     => __( 'Human usernames are rarely this long without separators.', 'ew-user-cleaner' ),
				'matches' => array( 'aaaabbbbccccddddeeeeffff' ),
				'ignores' => array( 'jonathan.smith' ),
			),
		);
	}

	/**
	 * Email local part recipes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function email_patterns(): array {
		return array(
			array(
				'title'   => __( 'Role or throwaway inbox', 'ew-user-cleaner' ),
				'pattern' => '(?:test|demo|noreply|no-reply|sample|dummy|asdf)[0-9]*',
				'why'     => __( 'Placeholder addresses used to pass a signup form.', 'ew-user-cleaner' ),
				'matches' => array( 'test@example.com', 'demo42@example.com' ),
				'ignores' => array( 'testing@example.com', 'contest@example.com' ),
			),
			array(
				'title'   => __( 'Name plus long digit tail', 'ew-user-cleaner' ),
				'pattern' => '[a-z]{2,12}[0-9]{6,}',
				'why'     => __( 'Mass created mailbox naming.', 'ew-user-cleaner' ),
				'matches' => array( 'john123456@example.com' ),
				'ignores' => array( 'john12@example.com' ),
			),
			array(
				'title'   => __( 'Gmail dot or plus alias abuse', 'ew-user-cleaner' ),
				'pattern' => '[a-z0-9.]+\+[a-z0-9]+',
				'why'     => __( 'One inbox creating many accounts through plus addressing.', 'ew-user-cleaner' ),
				'matches' => array( 'realuser+shop1@example.com' ),
				'ignores' => array( 'realuser@example.com' ),
			),
			array(
				'title'   => __( 'Random hash mailbox', 'ew-user-cleaner' ),
				'pattern' => '[a-f0-9]{16,}',
				'why'     => __( 'Disposable services often generate hex mailboxes.', 'ew-user-cleaner' ),
				'matches' => array( 'c3f9a1d8b7e40021@example.com' ),
				'ignores' => array( 'anna@example.com' ),
			),
			array(
				'title'   => __( 'Vowel free local part', 'ew-user-cleaner' ),
				'pattern' => '[bcdfghjklmnpqrstvwxyz0-9]{8,}',
				'why'     => __( 'Generated strings with no vowels. Pair with another signal.', 'ew-user-cleaner' ),
				'matches' => array( 'qwrtzpsdfg@example.com' ),
				'ignores' => array( 'jonathan@example.com' ),
			),
			array(
				'title'   => __( 'Spam keywords in the mailbox', 'ew-user-cleaner' ),
				'pattern' => '.*(?:casino|crypto|escort|viagra|payday).*',
				'why'     => __( 'Advertising addresses. Tune the word list to what you actually receive, and avoid short words that appear inside real names.', 'ew-user-cleaner' ),
				'matches' => array( 'cheapcasino@example.com' ),
				'ignores' => array( 'joanne@example.com', 'seoul.kim@example.com' ),
			),
			array(
				'title'   => __( 'Single character mailbox', 'ew-user-cleaner' ),
				'pattern' => '[a-z0-9]',
				'why'     => __( 'One letter mailboxes are almost never real on public signup forms.', 'ew-user-cleaner' ),
				'matches' => array( 'x@example.com' ),
				'ignores' => array( 'xy@example.com' ),
			),
			array(
				'title'   => __( 'Repeated character padding', 'ew-user-cleaner' ),
				'pattern' => '.*(?:aaa|zzz|000|111|xxx).*',
				'why'     => __( 'Filler characters used to make an address unique.', 'ew-user-cleaner' ),
				'matches' => array( 'annaaa@example.com' ),
				'ignores' => array( 'anna@example.com' ),
			),
			array(
				'title'   => __( 'Name separated by many dots', 'ew-user-cleaner' ),
				'pattern' => '(?:[a-z0-9]+\.){3,}[a-z0-9]+',
				'why'     => __( 'Alias generation using dot separated fragments.', 'ew-user-cleaner' ),
				'matches' => array( 'a.b.c.d@example.com' ),
				'ignores' => array( 'john.doe@example.com' ),
			),
			array(
				'title'   => __( 'Very long mailbox', 'ew-user-cleaner' ),
				'pattern' => '[a-z0-9._%+-]{32,}',
				'why'     => __( 'Extremely long local parts are typically machine generated.', 'ew-user-cleaner' ),
				'matches' => array( 'aaaabbbbccccddddeeeeffffgggghhhh@example.com' ),
				'ignores' => array( 'john.doe@example.com' ),
			),
		);
	}

	/**
	 * Suggested flagged domain seeds, grouped for clarity.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function domain_examples(): array {
		return array(
			__( 'US carrier SMS gateways', 'ew-user-cleaner' )   => array( 'txt.att.net', 'vtext.com', 'tmomail.net', 'messaging.sprintpcs.com' ),
			__( 'Common disposable mail', 'ew-user-cleaner' )    => array( 'mailinator.com', 'guerrillamail.com', 'yopmail.com', '10minutemail.com', 'sharklasers.com' ),
			__( 'Throwaway forwarders', 'ew-user-cleaner' )      => array( 'temp-mail.org', 'trashmail.com', 'getnada.com' ),
		);
	}
}
