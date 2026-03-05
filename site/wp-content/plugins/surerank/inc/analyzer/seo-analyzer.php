<?php
/**
 * SEO Analyzer class.
 *
 * Performs SEO checks on HTML content with consistent output for UI.
 *
 * @package SureRank\Inc\Analyzer
 */

namespace SureRank\Inc\Analyzer;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use SureRank\Inc\API\Analyzer;
use SureRank\Inc\Functions\Get;
use WP_Error;

/**
 * Class SeoAnalyzer
 *
 * Analyzes HTML content for SEO metrics with standardized output.
 */
class SeoAnalyzer {

	/**
	 * Instance object.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * DOMDocument instance containing parsed HTML.
	 *
	 * @var DOMDocument|null
	 */
	private $dom = null;

	/**
	 * Array of error messages encountered during analysis.
	 *
	 * @var array<string>
	 */
	private $errors = [];

	/**
	 * Base URL being analyzed.
	 *
	 * @var string
	 */
	private $base_url = '';

	/**
	 * Scraper instance for fetching content.
	 *
	 * @var Scraper
	 */
	private $scraper;

	/**
	 * Parser instance for parsing HTML.
	 *
	 * @var Parser
	 */
	private $parser;

	/**
	 * Cached HTML content.
	 *
	 * @var string|WP_Error
	 */
	private $html_content = '';

	/**
	 * Constructor.
	 *
	 * @param string $url The URL to analyze.
	 * @return void
	 */
	public function __construct( string $url ) {
		$this->scraper = Scraper::get_instance();
		$this->parser  = Parser::get_instance();
		$this->initialize( $url );
	}

	/**
	 * Initiator.
	 *
	 * @since 1.0.0
	 * @param string $url The URL to analyze.
	 * @return self initialized object of class.
	 */
	public static function get_instance( $url ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $url );
		}
		return self::$instance;
	}

	/**
	 * Get XPath instance for DOMDocument.
	 *
	 * @return DOMXPath|array<string, mixed>
	 */
	public function get_xpath() {
		if ( $this->dom === null ) {
			return [
				'exists'  => true,
				'status'  => 'error',
				'details' => $this->errors,
				'message' => __( 'Failed to load DOM for analysis.', 'surerank' ),
			];
		}

		return new DOMXPath( $this->dom );
	}

	/**
	 * Analyze page title.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	public function analyze_title( DOMXPath $xpath ) {

		$helptext = [
			sprintf(
				'<h6> %s </h6>',
				__( 'Homepage SEO Title', 'surerank' )
			),
			__( 'Your homepage SEO title is one of the most important signals you share with search engines and visitors. It becomes the main clickable headline that appears in search results, almost like your site\'s introduction card to the world. A clear and thoughtful title helps people understand what your site is about before they visit, and gives search engines a strong sense of how to categorize your homepage.', 'surerank' ),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-SEO-Title.webp' ),
				esc_attr( 'Homepage SEO Title' )
			),
			sprintf(
				'<h6>💡 %s </h6>',
				__( 'Why this matters:', 'surerank' )
			),
			__( 'Your homepage title shapes the first impression your site makes in search results. Search engines use it to understand what your homepage represents, and visitors rely on it to decide whether your site matches what they\'re looking for.', 'surerank' ),
			__( 'If the title is missing, unclear, or too long to display fully, your site may not stand out the way it should. A meaningful title makes your listing easier to read, more relevant, and more inviting.', 'surerank' ),
			__( 'A good homepage title also helps set expectations. It tells visitors who you are and what you offer in just a few words, helping your site feel more familiar and approachable before they even arrive.', 'surerank' ),

			sprintf(
				'<h6>✅ %s </h6>',
				__( 'How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Start with your site or brand name so visitors immediately know who you are.', 'surerank' ),
					__( 'Add a short, descriptive phrase that explains what your site offers and who it\'s for.', 'surerank' ),
					__( 'Keep the title under 60 characters so it displays cleanly in search results.', 'surerank' ),
					__( 'Use clear, friendly language that matches the tone of your site.', 'surerank' ),
					__( 'Write with real people in mind since this is often their first interaction with your content.', 'surerank' ),
				],
			],

			sprintf(
				'<h6>📌 %s </h6>',
				__( 'Example', 'surerank' )
			),
			__( 'Bright Bakes – Easy Baking Recipes for Everyone', 'surerank' ),
			__( 'This simple title explains the brand, the purpose and the value in one quick line.', 'surerank' ),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-SEO-Title-example.webp' ),
				esc_attr( 'Homepage SEO Title Example' )
			),
			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to update it', 'surerank' )
			),
			__( 'Where you edit your homepage title depends on how your homepage is set up in WordPress.', 'surerank' ),
			sprintf(
				/* translators: %s is the URL of the homepage settings page */
				__( 'You can <a href="%s">edit that page directly</a> and update the title from the Surerank Meta Box or page settings.', 'surerank' ),
				$this->get_homepage_settings_url()
			),

			sprintf(
				'<h6>🌟 %s </h6>',
				__( 'How SureRank helps', 'surerank' )
			),
			__( 'SureRank SEO Analysis checks whether your homepage has an SEO title and highlights when one is missing. This gives you a clear reminder to set a meaningful, human-friendly title that helps search engines understand your page and gives visitors a confident first impression.', 'surerank' ),
		];

		$titles = $xpath->query( '//title' );
		if ( ! $titles instanceof DOMNodeList ) {
			return $this->build_error_response(
				__( 'Search engine title is missing on the homepage.', 'surerank' ),
				$helptext,
				__( 'Search engine title is missing on the homepage.', 'surerank' ),
				'error'
			);
		}

		$exists  = $titles->length > 0;
		$content = '';
		$length  = 0;

		if ( $exists ) {
			$title_node = $titles->item( 0 );
			if ( $title_node instanceof DOMElement ) {
				$content = trim( $title_node->textContent );
				$length  = mb_strlen( $content );
			}
		}

		if ( ! $exists ) {
			$status = 'error';
		} elseif ( $length > Get::TITLE_LENGTH ) {
			$status = 'warning';
		} else {
			$status = 'success';
		}

		return [
			'exists'      => $exists,
			'status'      => $status,
			'description' => $helptext,
			'message'     => $this->get_title_message( $exists, $length, $status ),
		];
	}

	/**
	 * Analyze meta description.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	public function analyze_meta_description( DOMXPath $xpath ) {

		$description = [
			sprintf(
				'<h6> %s </h6>',
				__( 'Homepage SEO Description', 'surerank' )
			),
			__( 'Your homepage description is the short text that appears below your title in search results. It acts like a quick summary that helps people understand what your site offers. Think of it as a one sentence pitch that gives visitors a clear idea of what to expect and why your site is worth opening.', 'surerank' ),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-SEO-Description.webp' ),
				esc_attr( 'Homepage SEO Description' )
			),
			sprintf(
				'<h6>💡 %s </h6>',
				__( 'Why this matters:', 'surerank' )
			),
			__( 'A strong description helps search engines understand your homepage and gives visitors helpful context before they decide to explore your site.', 'surerank' ),
			__( 'When the description is missing, too long, or unclear, your search result may feel incomplete or get cut off, which can make it less appealing. A well written description improves how your listing looks and can directly influence how many people choose to visit your site.', 'surerank' ),
			__( 'Keeping the description between 150 to 160 characters works best. This gives you enough space to say something meaningful while staying short enough to show fully in search results.', 'surerank' ),

			sprintf(
				'<h6>✅ %s </h6>',
				__( 'How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Describe your site the way you would to someone who has never visited it before.', 'surerank' ),
					__( 'Focus on what your site offers and who it is for.', 'surerank' ),
					__( 'Keep it short, specific and friendly.', 'surerank' ),
					__( 'Avoid long phrases that might get cut off in search results.', 'surerank' ),
				],
			],

			sprintf(
				'<h6>📌 %s </h6>',
				__( 'Example', 'surerank' )
			),
			__( 'Discover simple, healthy meals you can cook at home, even if you\'re short on time.', 'surerank' ),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-SEO-Description-Example.webp' ),
				esc_attr( 'Homepage SEO Description Example' )
			),
			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to update it', 'surerank' )
			),
			__( 'Where you edit your homepage description depends on how your homepage is set up.', 'surerank' ),
			sprintf(
				/* translators: %s is the URL of the homepage settings page */
				__( 'You can <a href="%s">edit that page directly</a> to update the description.', 'surerank' ),
				$this->get_homepage_settings_url()
			),

			sprintf(
				'<h6>🌟 %s </h6>',
				__( 'How SureRank helps', 'surerank' )
			),
			__( 'SureRank SEO Analysis checks whether your homepage description is present and the right length. It highlights when the description is missing or too long, giving you a clear reminder to keep it readable, helpful, and easy for both visitors and search engines to understand.', 'surerank' ),
		];
		$meta_desc = $xpath->query( '//meta[@name="description"]/@content' );
		if ( ! $meta_desc instanceof DOMNodeList ) {
			return $this->build_error_response(
				__( 'Search engine description is missing on the homepage.', 'surerank' ),
				$description,
				__( 'Search engine description is missing on the homepage.', 'surerank' ),
				'warning'
			);
		}

		$exists  = $meta_desc->length > 0;
		$content = '';
		$length  = 0;

		if ( $exists ) {
			$meta_node = $meta_desc->item( 0 );
			if ( $meta_node instanceof DOMAttr ) {
				$content = trim( $meta_node->value );
				$length  = mb_strlen( $content );
			}
		}

		if ( ! $exists ) {
			$status = 'warning';
		} elseif ( $length > Get::DESCRIPTION_LENGTH ) {
			$status = 'warning';
		} else {
			$status = 'success';
		}

		return [
			'exists'      => $exists,
			'status'      => $status,
			'description' => $description,
			'message'     => $this->get_meta_description_message( $exists, $length, $status ),
		];
	}

	/**
	 * Analyze headings (H1).
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	public function analyze_heading_h1( DOMXPath $xpath ) {
		$h1_analysis = $this->analyze_h1( $xpath );

		$exists = $h1_analysis['exists'];
		$status = 'success';
		$title  = __( 'Homepage contains one H1 heading', 'surerank' );

		$descriptions = [
			sprintf(
				'<h6> %s </h6>',
				__( 'Homepage H1 Heading', 'surerank' )
			),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-H1-Heading.webp' ),
				esc_attr( 'Homepage H1 Heading' )
			),
			__( 'The H1 heading is the main title that appears on your homepage. It is often the first piece of text visitors notice, and it helps search engines understand what the page represents. Think of it as the cover of a book that tells people exactly what they have opened.', 'surerank' ),

			sprintf(
				'<h6>💡 %s </h6>',
				__( 'Why this matters:', 'surerank' )
			),
			__( 'Search engines rely on the H1 to understand the primary topic of the page. Visitors use it to quickly confirm they are in the right place. If the H1 is missing, unclear, or repeated several times, your homepage can feel confusing or unfinished.', 'surerank' ),
			__( 'Having one clear H1 gives your page structure, makes your message easier to understand, and improves how search engines interpret your content.', 'surerank' ),
			__( 'Keeping a single H1 on each page also helps establish a clean hierarchy. It shows what the page is mainly about, while other headings can support the content without competing with the primary message.', 'surerank' ),

			sprintf(
				'<h6>✅ %s </h6>',
				__( 'How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Use one H1 heading on your homepage and make sure it is the main title.', 'surerank' ),
					__( 'Write it clearly so visitors immediately know what your site offers.', 'surerank' ),
					__( 'Avoid generic phrases that do not communicate your purpose.', 'surerank' ),
					__( 'Keep it short and focused so it feels natural and easy to read.', 'surerank' ),
				],
			],

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-H1-Heading-Example.webp' ),
				esc_attr( 'H1 Heading Examples' )
			),

			sprintf(
				'<h6>📌 %s </h6>',
				__( 'Example', 'surerank' )
			),
			__( 'Helping You Learn to Code from Scratch', 'surerank' ),
			__( 'Creative Interior Design for Modern Spaces', 'surerank' ),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to update it', 'surerank' )
			),
			__( 'Where you add or edit your H1 depends on how your homepage is set up.', 'surerank' ),
			sprintf(
				/* translators: %s is the URL of the homepage settings page */
				__( 'You can <a href="%s">edit that page directly</a> to update the H1 heading.', 'surerank' ),
				$this->get_homepage_settings_url()
			),

			sprintf(
				'<h6>🌟 %s </h6>',
				__( 'How SureRank helps', 'surerank' )
			),
			__( 'SureRank SEO Analysis checks whether your homepage has a proper H1 heading and alerts you when it is missing. This gives you a simple reminder to create a clear, helpful title that improves your page structure for visitors and search engines.', 'surerank' ),
		];

		if ( ! $h1_analysis['exists'] ) {
			$status = 'warning';
			$title  = __( 'No H1 heading found on the homepage.', 'surerank' );
		} elseif ( ! $h1_analysis['is_optimized'] ) {
			$status = 'warning';
			$title  = __( 'Multiple H1 headings found on the homepage.', 'surerank' );
		} else {
			$title = __( 'Homepage contains one H1 heading.', 'surerank' );
		}

		return [
			'exists'      => $exists,
			'status'      => $status,
			'description' => $descriptions,
			'message'     => $title,
			'not_fixable' => true,
		];
	}

	/**
	 * Analyze H2 headings.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	public function analyze_heading_h2( DOMXPath $xpath ) {

		$descriptions = [
			sprintf(
				'<h6> %s </h6>',
				__( 'Homepage H2 Headings', 'surerank' )
			),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/h2-heading-image-1.webp' ),
				esc_attr( 'H2 Heading Banner' )
			),

			__( 'H2 headings act like section titles that break your homepage into clear, readable parts. They help visitors understand what each section is about without having to read everything line by line. Think of them as signposts that guide people as they scroll, making it easier to spot what matters most.', 'surerank' ),
			__( 'Search engines also pay attention to these subheadings. They use H2s to understand how your page is structured and what each section focuses on. Clear H2 headings help both visitors and search engines follow your content more naturally.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '💡 Why it matters', 'surerank' )
			),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/h2-heading-image-2.webp' ),
				esc_attr( 'H2 Comparison' )
			),

			__( 'Most people scan a homepage before they read it. Without H2 headings, your content can feel flat or overwhelming, even if the information is good. Well-placed H2s make your homepage easier to scan, easier to understand, and more inviting to explore.', 'surerank' ),
			__( 'From a search perspective, H2 headings give structure to your page. They help search engines understand the different topics covered on your homepage, which improves clarity and supports better visibility.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '✅ How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Add at least one H2 heading to your homepage to introduce a section.', 'surerank' ),
					__( 'Use H2s to separate key parts like services, features, or values.', 'surerank' ),
					__( 'Keep each heading short and clear so it is easy to scan.', 'surerank' ),
					__( 'Make sure each H2 matches the content that follows it.', 'surerank' ),
				],
			],

			sprintf(
				'<h6> %s </h6>',
				__( '🚀 Example', 'surerank' )
			),
			[
				'list' => [
					__( 'What We Do', 'surerank' ),
					__( 'How It Works', 'surerank' ),
					__( 'Trusted by 100+ Clients', 'surerank' ),
				],
			],
			__( 'Each of these helps visitors understand the section before reading the details.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '🔧 Where to update it', 'surerank' )
			),
			__( 'Edit your homepage and look for natural section breaks where a heading makes sense. If your homepage shows your latest posts, you can add H2 headings through your homepage layout or content blocks.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '🌟 How SureRank helps', 'surerank' )
			),
			__( 'SureRank SEO Analysis checks whether your homepage includes at least one H2 heading. When none are found, it gently highlights this so you can add simple section headings for better readability for visitors and clarity for search engines.', 'surerank' ),
		];

		$h2_analysis = $this->analyze_h2( $xpath );

		$exists = $h2_analysis['exists'];
		$status = 'success';
		$title  = __( 'Homepage contains at least one H2 heading', 'surerank' );

		if ( ! $h2_analysis['exists'] ) {
			$status = 'warning';
			$title  = __( 'Homepage does not contain at least one H2 heading', 'surerank' );
		}

		return [
			'exists'      => $exists,
			'status'      => $status,
			'description' => $descriptions,
			'message'     => $title,
			'not_fixable' => true,
		];
	}

	/**
	 * Analyze images for ALT attributes.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	public function analyze_images( DOMXPath $xpath ) {
		$images = $xpath->query( '//img' );
		if ( ! $images instanceof DOMNodeList ) {
			return [
				'exists'      => false,
				'status'      => 'warning',
				'description' => $this->build_image_description( false, 0, 0, [] ),
				'message'     => __( 'No images found on the homepage.', 'surerank' ),
			];
		}

		$total              = $images->length;
		$missing_alt        = 0;
		$missing_alt_images = [];

		foreach ( $images as $img ) {
			if ( $img instanceof DOMElement ) {
				$src = $img->hasAttribute( 'src' )
					? trim( $img->getAttribute( 'src' ) )
					: '';
				if ( ! $img->hasAttribute( 'alt' ) || empty( trim( $img->getAttribute( 'alt' ) ) ) ) {
					$missing_alt++;
					$missing_alt_images[] = $src;
				}
			}
		}

		$exists       = $total > 0;
		$is_optimized = $missing_alt === 0;

		if ( ! $exists ) {
			return [
				'exists'      => false,
				'status'      => 'warning',
				'description' => [ __( 'The homepage does not contain any images.', 'surerank' ) ],
				'message'     => __( 'No images found on the homepage.', 'surerank' ),
			];
		}

		$title = $is_optimized ? __( 'Images on the homepage have alt text attributes.', 'surerank' ) : __( 'Images on the homepage do not have alt text attributes.', 'surerank' );

		return [
			'exists'      => $exists,
			'status'      => $is_optimized ? 'success' : 'warning',
			'description' => $this->build_image_description( $exists, $total, $missing_alt, $missing_alt_images ),
			'message'     => $title,
		];
	}

	/**
	 * Analyze internal links if any.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	public function analyze_links( DOMXPath $xpath ) {
		$links    = $xpath->query( '//a[@href]' );
		$helptext = [
			sprintf(
				'<h6> %s </h6>',
				__( 'Homepage Internal Links', 'surerank' )
			),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Internal-links-1.webp' ),
				esc_attr( 'Homepage Internal Links' )
			),
			__( 'Your homepage is often the first place visitors arrive so should help them move easily to the most important parts of your site. Internal links act like gentle signposts that guide people toward key pages, whether that\'s your services, blog, or contact page. They also help search engines understand how your site is organized.', 'surerank' ),

			sprintf(
				'<h6>💡 %s </h6>',
				__( 'Why this matters:', 'surerank' )
			),
			__( 'A homepage without internal links can feel like a dead end. Visitors may not know where to go next, and search engines may struggle to understand which pages matter most. When your homepage includes clear links to important sections, it guides visitors to take action and helps your site perform better in search.', 'surerank' ),

			sprintf(
				'<h6>✅ %s </h6>',
				__( 'How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Add links to your about page, product or service pages, blog posts, or contact page.', 'surerank' ),
					__( 'Use buttons, menus, or simple text links, whichever feels most natural.', 'surerank' ),
					__( 'Choose links that help visitors understand what to do next.', 'surerank' ),
					__( 'Keep links easy to spot so people don\'t miss them.', 'surerank' ),
				],
			],

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Internal-links-2.webp' ),
				esc_attr( 'Internal Links Comparison' )
			),

			sprintf(
				'<h6>📌 %s </h6>',
				__( 'Example', 'surerank' )
			),
			__( 'A short welcome section on your homepage might end with a button leading to your services page and another pointing to your latest blog post. This gives visitors clear next steps and helps search engines see those pages as important.', 'surerank' ),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to update it', 'surerank' )
			),
			__( 'Edit your homepage and look for natural places to add links, such as under introductions, inside feature blocks, or near calls to action.', 'surerank' ),

			sprintf(
				'<h6>🌟 %s </h6>',
				__( 'How SureRank helps', 'surerank' )
			),
			__( 'SureRank checks whether your homepage includes helpful internal links and highlights it when none are found. This gives you a simple reminder to guide visitors forward and strengthen your site\'s structure without needing to think about technical SEO.', 'surerank' ),
		];

		if ( ! $links instanceof DOMNodeList ) {
			return [
				'exists'      => false,
				'status'      => 'warning',
				'description' => [
					$helptext,
				],
				'message'     => __( 'Home Page does not contain internal links to other pages on the site.', 'surerank' ),
				'not_fixable' => true,
			];
		}

		$internal       = 0;
		$internal_links = [];

		foreach ( $links as $link ) {
			if ( $link instanceof DOMElement ) {
				$href = $link->getAttribute( 'href' );
				if ( empty( $href ) || strpos( $href, '#' ) === 0 ) {
					continue;
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! is_string( $host ) || $host === $this->base_url ) {
					$internal++;
					$internal_links[] = $href;
				}
			}
		}

		$exists = $internal > 0;
		$title  = $exists ? __( 'Home Page contains internal links to other pages on the site.', 'surerank' ) : __( 'Home Page does not contain internal links to other pages on the site.', 'surerank' );
		return [
			'exists'      => $exists,
			'status'      => $exists ? 'success' : 'warning',
			'description' => $helptext,
			'message'     => $title,
			'not_fixable' => true,
		];
	}

	/**
	 * Analyze canonical tag.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	public function analyze_canonical( DOMXPath $xpath ) {
		$helptext = [
			sprintf(
				'<h6>💡 %s </h6>',
				__( 'Homepage Canonical Tag', 'surerank' )
			),
			__( 'Your homepage can sometimes be reached through more than one URL, even if the page looks exactly the same. This can happen with or without a trailing slash, or when links add extra parameters. A canonical tag tells search engines which version of your homepage should be treated as the main one. It works like gently pointing to the correct doorway and saying, "This is the page to follow."', 'surerank' ),

			sprintf(
				'<h6>💡 %s </h6>',
				__( 'Why this matters:', 'surerank' )
			),
			__( 'If search engines find several versions of your homepage, they may treat them as separate pages. This can split your ranking strength across multiple URLs and make your homepage look less important than it really is. A clear canonical tag keeps everything focused, helping search engines understand which version should receive full credit.', 'surerank' ),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-Canonical-Tag.webp' ),
				esc_attr( 'Canonical Tag Comparison' )
			),

			sprintf(
				'<h6>✅ %s </h6>',
				__( 'How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Choose one preferred homepage URL, such as https://example.com/.', 'surerank' ),
					__( 'Set a canonical tag that points to that exact URL.', 'surerank' ),
					__( 'Keep the canonical link consistent so search engines aren\'t confused.', 'surerank' ),
					__( 'Avoid sharing alternate homepage URLs with extra parameters when possible.', 'surerank' ),
				],
			],

			sprintf(
				'<h6>📌 %s </h6>',
				__( 'Example', 'surerank' )
			),
			__( 'If your homepage loads at both https://example.com and https://example.com/?ref=newsletter, a canonical tag ensures search engines treat https://example.com as the main version.', 'surerank' ),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to update it', 'surerank' )
			),
			__( 'You can set your canonical tag directly from the SureRank Meta Box by opening the Advanced tab and filling in the Canonical Tag field.', 'surerank' ),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-Canonical-Tag-Example.webp' ),
				esc_attr( 'Canonical URL Field' )
			),

			sprintf(
				'<h6>🌟 %s </h6>',
				__( 'How SureRank helps', 'surerank' )
			),
			__( 'SureRank SEO Analysis checks whether your homepage has a canonical tag and lets you know when one is missing. This gives you a clear, simple reminder to keep your homepage focused on a single, clean URL that search engines can trust.', 'surerank' ),
		];

		$canonical = $xpath->query( '//link[@rel="canonical"]/@href' );
		if ( ! $canonical instanceof DOMNodeList ) {
			return $this->build_error_response(
				__( 'Canonical tag is not present on the homepage.', 'surerank' ),
				$helptext,
				__( 'Canonical tag is not present on the homepage.', 'surerank' ),
				'warning'
			);
		}

		$exists = $canonical->length > 0;
		$title  = $exists ? __( 'Canonical tag is present on the homepage.', 'surerank' ) : __( 'Canonical tag is not present on the homepage.', 'surerank' );
		return [
			'exists'      => $exists,
			'status'      => $exists ? 'success' : 'warning',
			'description' => $helptext,
			'message'     => $title,
		];
	}

	/**
	 * Analyze indexing meta tag.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	public function analyze_indexing( DOMXPath $xpath ) {
		$robots      = $xpath->query( '//meta[@name="robots"]/@content' );
		$description = [
			sprintf(
				'<h6> %s </h6>',
				__( 'Homepage Indexing', 'surerank' )
			),
			__( 'For your site to appear in search results, your homepage needs to be indexable. Indexing means giving search engines permission to include your homepage in their listings so people can find it. If indexing is turned off, your homepage becomes invisible to search engines even if everything else on your site looks correct.', 'surerank' ),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-Indexing.webp' ),
				esc_attr( 'Homepage Indexing' )
			),
			sprintf(
				'<h6>💡 %s </h6>',
				__( 'Why this matters:', 'surerank' )
			),
			__( 'Your homepage is often the first place search engines and visitors try to reach. When indexing is blocked, search engines cannot list your homepage at all. This can make your entire site harder to discover, especially for people searching for your brand or business by name.', 'surerank' ),
			__( 'A homepage that is indexable gives search engines a clear invitation to include your site in search results.', 'surerank' ),
			__( 'Indexing issues can happen by accident during setup, theme changes, or plugin settings. It is important to check that your homepage is allowed to be indexed so nothing holds back your visibility.', 'surerank' ),

			sprintf(
				'<h6>✅ %s </h6>',
				__( 'How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Make sure your homepage is set to allow indexing in your SEO settings.', 'surerank' ),
					__( 'Review your global visibility settings to confirm nothing is blocking your site.', 'surerank' ),
					__( 'Check the Advanced section inside your SureRank Meta Box to ensure indexing is enabled.', 'surerank' ),
					__( 'After updating your settings, open your homepage and confirm it loads normally.', 'surerank' ),
				],
			],

			sprintf(
				'<h6>📌 %s </h6>',
				__( 'Example', 'surerank' )
			),
			__( 'If indexing was turned off while your site was being built, your homepage may never appear in search results after launch. Enabling indexing allows search engines to include it again.', 'surerank' ),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-Indexing-Example.webp' ),
				esc_attr( 'Robot Instructions' )
			),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to update it', 'surerank' )
			),
			__( 'You can check and update your homepage indexing preferences from the Advanced settings inside the SureRank Meta Box. You can also review your global visibility settings in WordPress to make sure nothing is preventing your site from being indexed.', 'surerank' ),

			sprintf(
				'<h6>🌟 %s </h6>',
				__( 'How SureRank helps', 'surerank' )
			),
			__( 'SureRank SEO Analysis checks whether your homepage is indexable and alerts you when it is blocked. This gives you a clear and simple reminder to allow indexing so search engines can include your site and help more people find what you offer.', 'surerank' ),
		];

		if ( ! $robots instanceof DOMNodeList ) {
			return [
				'exists'      => false,
				'status'      => 'warning',
				'description' => $description,
				'message'     => __( 'Homepage is not indexable by search engines.', 'surerank' ),
			];
		}

		$exists = $robots->length > 0;

		$content = '';

		if ( $exists ) {
			$robots_node = $robots->item( 0 );
			if ( $robots_node instanceof DOMAttr ) {
				$content = trim( $robots_node->value );
			}
		}

		$is_indexable = $exists ? strpos( $content, 'noindex' ) === false : true;
		$title        = $is_indexable ? __( 'Homepage is indexable by search engines.', 'surerank' ) : __( 'Homepage is not indexable by search engines.', 'surerank' );

		return [
			'exists'      => $exists,
			'status'      => $is_indexable ? 'success' : 'error',
			'description' => $description,
			'message'     => $title,
		];
	}

	/**
	 * Analyze homepage reachability.
	 *
	 * @return array<string, mixed>
	 */
	public function analyze_reachability() {
		$home_url          = home_url();
		$is_reachable      = $this->base_url === wp_parse_url( $home_url, PHP_URL_HOST ) && ! is_wp_error( $this->html_content );
		$working_label     = __( 'Home Page is loading correctly.', 'surerank' );
		$not_working_label = __( 'Home Page is not loading correctly.', 'surerank' );

		$description = [
			sprintf(
				'<h6> %s </h6>',
				__( 'Homepage Is Reachable', 'surerank' )
			),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-Reachable.webp' ),
				esc_attr( 'Homepage Is Reachable' )
			),
			__( 'Your homepage is often the first page visitors see and the starting point search engines use to understand your site. If the homepage doesn\'t load or shows an error, both visitors and search engines may have trouble reaching your content.', 'surerank' ),

			sprintf(
				'<h6>💡 %s </h6>',
				__( 'Why this matters:', 'surerank' )
			),
			__( 'When your homepage isn\'t reachable, it affects more than just one page. Search engines may not be able to crawl or index your site properly and visitors may leave before seeing anything else. A working homepage sets the tone for your entire site and makes sure people and search engines can explore it without problems.', 'surerank' ),

			sprintf(
				'<h6>✅ %s </h6>',
				__( 'How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Open your homepage in a browser and make sure it loads without errors.', 'surerank' ),
					__( 'Confirm that the page is published and not set to private or draft.', 'surerank' ),
					__( 'Check that no redirects or plugins are accidentally blocking access.', 'surerank' ),
					__( 'Review your WordPress homepage settings to ensure the correct page is selected.', 'surerank' ),
				],
			],

			sprintf(
				'<h6>📌 %s </h6>',
				__( 'Example', 'surerank' )
			),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-not-Reachable.webp' ),
				esc_attr( '503 Service Unavailable' )
			),
			__( 'If your homepage was deleted or changed to draft by mistake, visitors may see a 404 page instead.', 'surerank' ),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to update it', 'surerank' )
			),
			__( 'In WordPress, go to Settings → Reading and make sure the correct homepage is selected. If you use a static homepage, confirm that the page still exists, is published and loads normally when opened directly.', 'surerank' ),

			sprintf(
				'<h6>🌟 %s </h6>',
				__( 'How SureRank helps', 'surerank' )
			),
			__( 'SureRank SEO Analysis checks whether your homepage is reachable and flags it when it isn’t. This gives you a clear reminder to fix any loading issues so both visitors and search engines can access your site without interruption.', 'surerank' ),
		];

		if ( ! $is_reachable ) {
			$response     = $this->scraper->fetch( $home_url );
			$is_reachable = ! is_wp_error( $response );
		}

		$title = $is_reachable ? $working_label : $not_working_label;
		return [
			'exists'      => true,
			'status'      => $is_reachable ? 'success' : 'error',
			'description' => $description,
			'message'     => $title,
			'not_fixable' => true,
		];
	}

	/**
	 * Analyze secure HTTPS connection.
	 *
	 * @return array<string, mixed>
	 */
	public function analyze_secure_connection(): array {
		$header_url        = $this->fetch_header( 'x-final-url' );
		$effective_url     = $header_url !== '' ? $header_url : home_url();
		$working_label     = __( 'Site is served over a secure HTTPS connection.', 'surerank' );
		$not_working_label = __( 'Site is not served over a secure HTTPS connection.', 'surerank' );

		$description = [
			sprintf(
				'<h6> %s </h6>',
				__( 'Secure Connection (HTTPS)', 'surerank' )
			),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/HTTPS-1.webp' ),
				esc_attr( 'Secure Connection (HTTPS)' )
			),
			__( 'A secure connection means your website uses HTTPS, which is shown by the little padlock icon next to your site\'s address in a browser. It tells visitors and search engines that your site is safe and any information shared is protected.', 'surerank' ),

			sprintf(
				'<h6>💡 %s </h6>',
				__( 'Why it matters', 'surerank' )
			),
			__( 'Security is important for every site, even if you don\'t collect sensitive information. HTTPS helps protect visitors, builds trust, and signals to search engines that your site is reliable. Modern browsers may also warn visitors when a site isn\'t secure, so using HTTPS keeps people confident in your site.', 'surerank' ),

			sprintf(
				'<h6>✅ %s </h6>',
				__( 'How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Check your site shows the padlock icon in the browser address bar.', 'surerank' ),
					__( 'Make sure HTTPS is active by using an SSL certificate. Many hosting providers offer them for free.', 'surerank' ),
					__( 'Keep your certificate up to date so your connection remains secure.', 'surerank' ),
				],
			],

			sprintf(
				'<h6>📌 %s </h6>',
				__( 'Example', 'surerank' )
			),
			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/HTTPS-2.webp' ),
				esc_attr( 'HTTPS Example' )
			),
			__( 'A visitor sees the padlock on your blog or store and feels confident browsing or signing up for your newsletter. Without HTTPS, the same visitor might get a warning and leave immediately.', 'surerank' ),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to update it', 'surerank' )
			),
			__( 'Most hosting dashboards include an option to enable SSL or HTTPS for your site. This is often found under sections like Security, SSL, or Domains.', 'surerank' ),

			sprintf(
				'<h6>🌟 %s </h6>',
				__( 'How SureRank helps', 'surerank' )
			),
			__( 'SureRank confirms whether your site has HTTPS or not, so you can see the status clearly and see at a glance whether your connection is secure.', 'surerank' ),
		];

		$is_https = strpos( $effective_url, 'https://' ) === 0;
		$title    = $is_https ? $working_label : $not_working_label;
		return [
			'exists'      => true,
			'status'      => $is_https ? 'success' : 'warning',
			'description' => $description,
			'message'     => $title,
			'not_fixable' => true,
		];
	}

	/**
	 * Analyze open graph tags.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	public function open_graph_tags( DOMXPath $xpath ): array {
		$og_tags           = $xpath->query( "//meta[starts-with(@property, 'og:')]" );
		$working_label     = __( 'Open Graph tags are present on your homepage.', 'surerank' );
		$not_working_label = __( 'Open Graph tags are not present on your homepage.', 'surerank' );
		$helptext          = [
			sprintf(
				'<h6> %s </h6>',
				__( 'Homepage Open Graph Tags', 'surerank' )
			),
			__( 'When someone shares your site on social media or in a messaging app, the platform tries to show a preview with a title, description and image. Without Open Graph tags, they may pull random content or show no preview at all. Open Graph tags let you choose exactly what people see when your site is shared.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '💡 Why this matters:', 'surerank' )
			),
			__( 'A clean and attractive preview helps your site look more trustworthy and encourages people to click. If the preview is missing or doesn\'t show the right information, it can be harder for visitors to engage with your content. Setting these tags helps your site look polished wherever it appears.', 'surerank' ),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-Open-Graph-Tags-Why-this-matters.webp' ),
				esc_attr( 'Open Graph Tags Comparison' )
			),

			sprintf(
				'<h6> %s </h6>',
				__( '✅ How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Add a short, clear title that matches what your site is about.', 'surerank' ),
					__( 'Write a brief description that introduces your site in a friendly way.', 'surerank' ),
					__( 'Choose an image that represents your brand or homepage well.', 'surerank' ),
					__( 'Keep all three elements simple so the preview feels inviting.', 'surerank' ),
				],
			],

			sprintf(
				'<h6> %s </h6>',
				__( '🚀 Example', 'surerank' )
			),
			__( 'A homepage preview might show your site name, a short tagline and a clean image that reflects your brand or main topic.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '🔧 Where to update it', 'surerank' )
			),
			__( 'You can configure your homepage Open Graph settings from your Home Page Social settings or your global social settings, depending on how your homepage is set up.', 'surerank' ),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Where-to-update-Open-Graph-Tags.webp' ),
				esc_attr( 'Social Settings' )
			),

			sprintf(
				'<h6> %s </h6>',
				__( '🌟 How SureRank helps', 'surerank' )
			),
			__( 'SureRank SEO Analysis checks whether your homepage has Open Graph tags and shows when they are missing. This gives you a simple reminder to set a clear title, description, and image so your homepage always looks good when shared.', 'surerank' ),
		];

		if ( ! $og_tags instanceof DOMNodeList ) {
			return [
				'exists'      => false,
				'status'      => 'warning',
				'description' => $helptext,
				'message'     => $not_working_label,
			];
		}

		$details        = [];
		$required_tags  = [ 'og:title', 'og:description' ];
		$found_required = [
			'og:title'       => false,
			'og:description' => false,
		];

		foreach ( $og_tags as $tag ) {
			if ( $tag instanceof DOMElement ) {
				$property = $tag->getAttribute( 'property' );
				$content  = $tag->getAttribute( 'content' );

				$details[] = $property . ':' . $content;

				if ( in_array( $property, $required_tags, true ) ) {
					$found_required[ $property ] = true;
				}
			}
		}

		$missing_required = array_keys( array_filter( $found_required, static fn( $found) => ! $found ) );
		if ( ! empty( $missing_required ) ) {
			return [
				'exists'      => ! empty( $details ),
				'status'      => 'warning',
				'description' => $helptext,
				'message'     => $not_working_label,
			];
		}

		return [
			'exists'      => true,
			'status'      => 'success',
			'description' => $helptext,
			'message'     => $working_label,
		];
	}

	/**
	 * Analyze schema meta data.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	public function schema_meta_data( DOMXPath $xpath ) {
		$schema_meta_data  = $xpath->query( "//script[@type='application/ld+json']" );
		$working_label     = __( 'Structured data (schema) is present on the home page.', 'surerank' );
		$not_working_label = __( 'Structured data (schema) is not present on the home page.', 'surerank' );
		$helptext          = [
			sprintf(
				'<h6> %s </h6>',
				__( 'Homepage Schema Metadata', 'surerank' )
			),
			__( 'Schema works like a small cheat sheet your homepage shares with search engines. It quietly explains what type of page it is, such as a website homepage or a blog, so search engines can understand the content more clearly. This extra context helps them interpret your page better and can lead to richer details in search results.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '💡 Why this matters:', 'surerank' )
			),
			__( 'Search engines use signals beyond page text to understand your site. Schema provides those signals in a structured format. When your homepage includes the right schema, search results can show clearer details, like your site name or breadcrumb paths, before users click.', 'surerank' ),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Why-Homepage-Schema-Metadata-maters.webp' ),
				esc_attr( 'Schema Comparison' )
			),

			sprintf(
				'<h6> %s </h6>',
				__( '✅ How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Ensure your homepage includes essential structured data like WebPage schema.', 'surerank' ),
					__( 'Keep the schema feature enabled so search engines always receive the right signals.', 'surerank' ),
					__( 'Confirm that the schema being applied matches what your homepage represents.', 'surerank' ),
				],
			],

			sprintf(
				'<h6> %s </h6>',
				__( '🚀 Example', 'surerank' )
			),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/homepage-schema-example.webp' ),
				esc_attr( 'Schema Example' )
			),

			__( 'A homepage using WebPage schema will show context such as your site name or breadcrumb information in search results, helping visitors understand your site before they click.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '🔧 Where to update it', 'surerank' )
			),
			__( 'Schema is generated automatically by SureRank when the feature is enabled. To verify that it\'s being applied correctly, you can:', 'surerank' ),
			[
				'list' => [
					__( 'View the live page source and search for "schema" or "application/ld+json."', 'surerank' ),
					__( 'Use a schema validation tool to check what search engines can read.', 'surerank' ),
					__( 'Use the Schema Builder to see the schema SureRank prepares, then confirm it appears on the live page.', 'surerank' ),
				],
			],
			__( 'This helps you understand what should be output, but the live page check is the real confirmation.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '🌟 How SureRank helps', 'surerank' )
			),
			__( 'SureRank SEO Analysis checks whether your homepage schema is active and present. Once enabled, the plugin builds the structured data for you and gives you simple ways to confirm that search engines can read it correctly.', 'surerank' ),
		];

		if ( ! $schema_meta_data instanceof DOMNodeList ) {
			return [
				'exists'      => false,
				'status'      => 'suggestion',
				'description' => $helptext,
				'message'     => $not_working_label,
			];
		}

		if ( ! $schema_meta_data->length ) {
			return [
				'exists'      => false,
				'status'      => 'suggestion',
				'description' => $helptext,
				'message'     => $not_working_label,
			];
		}

		return [
			'exists'      => true,
			'status'      => 'success',
			'description' => $helptext,
			'message'     => $working_label,
		];
	}

	/**
	 * Analyze WWW canonicalization.
	 *
	 * @return array<string, mixed>
	 */
	public function analyze_www_canonicalization(): array {
		$home_url = home_url();
		$parsed   = wp_parse_url( $home_url );

		$helptext = [
			sprintf(
				'<h6> %s </h6>',
				__( '🌐 WWW Canonicalization', 'surerank' )
			),
			__( 'Your site can usually be accessed in two ways. One version uses www at the front of the address and the other does not. For example, https://example.com and https://www.example.com might both load your site, even though they look identical. Search engines see these as two separate versions unless one automatically redirects to the other.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '💡 Why it matters', 'surerank' )
			),
			__( 'When both versions load without a redirect, search engines may think you have duplicate copies of every page on your site. This can spread your SEO strength across two versions and make it harder for search engines to know which one to focus on. A single preferred version keeps everything consistent and helps search engines understand your site more clearly.', 'surerank' ),
			__( 'Choosing one version also gives visitors a cleaner experience. Visitors always land on the same address, which builds trust and avoids confusion.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '✅ How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Decide whether you want to use www or non www as your main address.', 'surerank' ),
					__( 'Set up a redirect so all traffic goes to your preferred version.', 'surerank' ),
					__( 'Make sure your internal links and sitemap match the version you choose.', 'surerank' ),
					__( 'Ask your hosting provider for help if you are unsure how to set up the redirect.', 'surerank' ),
				],
			],

			sprintf(
				'<h6> %s </h6>',
				__( '🚀 Example', 'surerank' )
			),
			__( 'If someone visits https://www.example.com but your preferred version is https://example.com, a redirect will automatically send them to the correct version. This keeps everything clean and consistent for search engines and visitors.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '🔧 Where to update it', 'surerank' )
			),
			__( 'You can configure this from your hosting panel or by adjusting your domain settings. Some hosts offer a simple toggle to enforce either www or non www. You can also ask your hosting support to set it up if you prefer an easier option.', 'surerank' ),

			sprintf(
				'<h6> %s </h6>',
				__( '🌟 How SureRank helps', 'surerank' )
			),
			__( 'SureRank SEO Analysis checks whether your site redirects properly between the www and non www versions. When it detects that both versions load separately, it gives you a clear reminder to set a single preferred version so your site stays consistent and easy for search engines to understand.', 'surerank' ),
		];

		$working_label     = __( 'Site correctly redirects between www and non-www versions.', 'surerank' );
		$not_working_label = __( 'Site does not correctly redirect between www and non-www versions.', 'surerank' );

		if ( ! is_array( $parsed ) || ! isset( $parsed['host'], $parsed['scheme'] ) ) {
			return [
				'exists'      => false,
				'status'      => 'error',
				'description' => $helptext,
				'message'     => $not_working_label,
				'not_fixable' => true,
			];
		}

		$host    = (string) $parsed['host'];
		$scheme  = (string) $parsed['scheme'];
		$timeout = 8;

		// Skip www canonicalization check for subdomain sites (e.g., subdomain.example.com).
		// This check only applies to root domains (example.com vs www.example.com).
		$host_parts   = explode( '.', $host );
		$is_subdomain = count( $host_parts ) > 2 && ! str_starts_with( $host, 'www.' );

		if ( $is_subdomain ) {
			return [
				'exists'      => true,
				'status'      => 'success',
				'description' => $helptext,
				'message'     => $working_label,
				'not_fixable' => true,
			];
		}

		$is_www    = str_starts_with( $host, 'www.' );
		$alternate = $is_www ? preg_replace( '/^www\./', '', $host ) : "www.{$host}";
		$test_url  = "{$scheme}://{$alternate}";

		// Pull the Location header (empty string on failure).
		$response = wp_safe_remote_head(
			$test_url,
			[
				'redirection' => 5,
				'timeout'     => $timeout,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'exists'      => false,
				'status'      => 'error',
				'description' => $helptext,
				'message'     => $not_working_label,
				'not_fixable' => true,
			];
		}

		$status_code  = (int) wp_remote_retrieve_response_code( $response );
		$raw_location = wp_remote_retrieve_header( $response, 'location' );

		$location = is_array( $raw_location )
			? ( $raw_location[0] ?? '' )
			: ( ! empty( $raw_location ) ? $raw_location : '' );

		// Normalize the final URL.
		if ( str_starts_with( $location, '/' ) ) {
			$final_url = "{$scheme}://{$host}{$location}";
		} elseif ( $location !== '' ) {
			$final_url = $location;
		} else {
			$final_url = $test_url;
		}

		$final_host        = (string) ( wp_parse_url( $final_url, PHP_URL_HOST ) ?? '' );
		$redirect_happened = $location !== '';
		$redirect_ok       = $redirect_happened ? $final_host === $host : true;
		$request_ok        = $status_code >= 200 && $status_code < 300;

		$all_good = $redirect_ok && $request_ok;

		$title = $all_good ? $working_label : $not_working_label;
		return [
			'exists'      => true,
			'status'      => $all_good ? 'success' : 'warning',
			'description' => $helptext,
			'message'     => $title,
			'not_fixable' => true,
		];
	}

	/**
	 * Initialize the analyzer by fetching and parsing the URL.
	 *
	 * @param string $url The URL to analyze.
	 * @return void
	 */
	private function initialize( string $url ) {

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$this->errors[] = __( 'Invalid URL.', 'surerank' );
			return;
		}

		$parsed_url         = wp_parse_url( $url, PHP_URL_HOST );
		$this->base_url     = is_string( $parsed_url ) ? $parsed_url : '';
		$this->html_content = $this->scraper->fetch( $url );

		if ( is_wp_error( $this->html_content ) ) {
			$this->errors[] = $this->html_content->get_error_message();
			return;
		}

		$parsed_dom = $this->parser->parse( $this->html_content );
		if ( is_wp_error( $parsed_dom ) ) {
			$this->errors[] = $parsed_dom->get_error_message();
			return;
		}

		$this->dom = $parsed_dom;
	}

	/**
	 * Get title analysis message.
	 *
	 * @param bool   $exists Whether title exists.
	 * @param int    $length Title length.
	 * @param string $status Status of the analysis.
	 * @return string
	 */
	private function get_title_message( bool $exists, int $length, string $status ) {
		if ( ! $exists ) {
			return __( 'Search engine title is missing on the homepage.', 'surerank' );
		}

		if ( $status === 'warning' ) {
			/* translators: %1$d is the maximum recommended length of the title. */
			$message = __( 'Search engine title of the home page exceeds %1$d characters.', 'surerank' );
			return sprintf( $message, Get::TITLE_LENGTH );
		}

		if ( $status === 'success' ) {
			return __( 'Search engine title of the home page is present and under 60 characters.', 'surerank' );
		}

		return __( 'Search engine title is present and under 60 characters.', 'surerank' );
	}

	/**
	 * Get meta description analysis message.
	 *
	 * @param bool   $exists Whether meta description exists.
	 * @param int    $length Meta description length.
	 * @param string $status Status of the analysis.
	 * @return string
	 */
	private function get_meta_description_message( bool $exists, int $length, string $status ) {
		if ( ! $exists ) {
			return __( 'Search engine description is missing on the homepage.', 'surerank' );
		}

		if ( $status === 'warning' ) {
			/* translators: %1$d is the maximum length of the meta description. */
			$message = __( 'Search engine description of the home page exceeds %1$d characters.', 'surerank' );
			return sprintf( $message, Get::DESCRIPTION_LENGTH );
		}

		if ( $status === 'success' ) {
			return __( 'Search engine description of the home page is present and under 160 characters.', 'surerank' );
		}

		return __( 'Search engine description is missing on the homepage.', 'surerank' );
	}

	/**
	 * Analyze H1 headings.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array{
	 *     exists: bool,
	 *     is_optimized: bool,
	 *     details: array{
	 *         count: int,
	 *         contents: array<string>
	 *     }
	 * }
	 */
	private function analyze_h1( DOMXPath $xpath ): array {
		$h1s = $xpath->query( '//h1' );
		if ( ! $h1s instanceof DOMNodeList ) {
			return [
				'exists'       => false,
				'is_optimized' => false,
				'details'      => [
					'count'    => 0,
					'contents' => [],
				],
			];
		}

		$exists   = $h1s->length > 0;
		$count    = $h1s->length;
		$contents = [];

		if ( $exists ) {
			foreach ( $h1s as $h1_node ) {
				if ( $h1_node instanceof DOMElement ) {
					$contents[] = trim( $h1_node->textContent );
				}
			}
		}

		return [
			'exists'       => $exists,
			'is_optimized' => $count === 1,
			'details'      => [
				'count'    => $count,
				'contents' => $contents,
			],
		];
	}

	/**
	 * Analyze H2 headings.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array<string, mixed>
	 */
	private function analyze_h2( DOMXPath $xpath ) {
		$h2s = $xpath->query( '//h2' );
		if ( ! $h2s instanceof DOMNodeList ) {
			return [
				'exists'       => false,
				'is_optimized' => false,
				'details'      => [
					'count'    => 0,
					'contents' => [],
				],
			];
		}

		$exists   = $h2s->length > 0;
		$count    = $h2s->length;
		$contents = [];

		if ( $exists ) {
			foreach ( $h2s as $h2_node ) {
				if ( $h2_node instanceof DOMElement ) {
					$contents[] = trim( $h2_node->textContent );
				}
			}
		}

		return [
			'exists'       => $exists,
			'is_optimized' => $count >= 1,
			'details'      => [
				'count'    => $count,
				'contents' => $contents,
			],
		];
	}

	/**
	 * Build image analysis description.
	 *
	 * @param bool          $exists Whether images exist.
	 * @param int           $total Total number of images.
	 * @param int           $missing_alt Number of images missing ALT.
	 * @param array<string> $missing_alt_images Images missing ALT attributes.
	 *  @return array<int, array<string, array<int, string>|string>|string>
	 */
	private function build_image_description( bool $exists, int $total, int $missing_alt, array $missing_alt_images ) {
		$list = [];
		if ( $missing_alt !== 0 ) {
			foreach ( $missing_alt_images as $image ) {
				if ( ! in_array( $image, $list ) ) {
					$list[] = esc_html( $image );
				}
			}
		}

		return [
			sprintf(
				'<h6> %s </h6>',
				__( 'Homepage Image ALT Text', 'surerank' )
			),
			__( 'Images add personality and meaning to your homepage, but search engines cannot understand them on their own. ALT text is a short written description that tells screen readers what an image shows, making your site more accessible for visitors who rely on assistive tools. It also gives search engines a bit more context, which can help them understand your page more clearly.', 'surerank' ),

			sprintf(
				'<h6>💡 %s </h6>',
				__( 'Why this matters:', 'surerank' )
			),
			__( 'ALT text plays an important role in accessibility by helping people who cannot see the images understand what is being shown. It also adds helpful context for search engines, especially when they try to understand the purpose of the images on your page. On your homepage, where key visuals often support your main message, clear ALT text makes your site more inclusive and easier to understand for everyone.', 'surerank' ),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-img-alt-text.webp' ),
				esc_attr( 'ALT Text Comparison' )
			),

			sprintf(
				'<h6>✅ %s </h6>',
				__( 'How to keep things smooth', 'surerank' )
			),
			[
				'list' => [
					__( 'Describe what the image is showing in clear, simple words.', 'surerank' ),
					__( 'Add ALT text to images that have meaning and skip for purely decorative ones.', 'surerank' ),
					__( 'Keep descriptions short so they feel natural when read aloud.', 'surerank' ),
					__( 'Write as if helping someone who cannot see the image.', 'surerank' ),
				],
			],

			sprintf(
				'<h6>📌 %s </h6>',
				__( 'Example', 'surerank' )
			),
			[
				'list' => [
					__( 'Woman doing yoga in a sunny room.', 'surerank' ),
					__( 'Handmade ceramic mug on wooden table.', 'surerank' ),
				],
			],

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to update it', 'surerank' )
			),
			sprintf(
			/* translators: %s is the URL of the WordPress Media Library */
				__( 'You can add ALT text in the <a href="%s">media settings</a> when you upload or edit an image.', 'surerank' ),
				admin_url( 'upload.php' )
			),
			__( 'Depending on your page builder, updating ALT text in the media library may not change images that are already placed on a page. Builders often store that information inside the block so you may need to open each image block and update the ALT text manually. New uploads usually apply the ALT text correctly from the start.', 'surerank' ),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/01/Homepage-img-alt-text-2.webp' ),
				esc_attr( 'Alternative Text Field' )
			),

			sprintf(
				'<h6>🌟 %s </h6>',
				__( 'How SureRank helps', 'surerank' )
			),
			__( 'SureRank checks whether your homepage images have ALT text and shows which ones are missing it. This helps you keep your site more accessible and ensures your important images are described clearly for every visitor.', 'surerank' ),
			[ 'list' => $list ],
		];
	}

	/**
	 * Build error response for invalid queries.
	 *
	 * @param string                                           $title Error title.
	 * @param array<string|array<string>|array<string, mixed>> $helptext Error description (HTML).
	 * @param string                                           $message Error message.
	 * @param string                                           $status Error status.
	 * @return array<string, mixed>
	 */
	private function build_error_response( string $title, array $helptext, string $message, string $status = 'error' ) {
		return [
			'exists'      => false,
			'status'      => $status,
			'description' => $helptext,
			'message'     => $message,
		];
	}

	/**
	 * Get the given header from the last fetched response.
	 *
	 * @param string $header The header name to retrieve.
	 * @return string        Header value, or '' if unavailable.
	 */
	private function fetch_header( string $header ): string {
		if ( is_wp_error( $this->html_content ) || empty( $this->scraper->get_body() ) ) {
			return '';
		}

		$value = $this->scraper->get_header( $header );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Get the URL of the home page social settings page.
	 *
	 * @return string
	 */
	private function get_homepage_settings_url() {
		$page_on_front = intval( Get::option( 'page_on_front' ) );
		if ( get_edit_post_link( $page_on_front ) ) {
			return get_edit_post_link( $page_on_front );
		}
		return Analyzer::get_instance()->get_surerank_settings_url( 'homepage', 'general' );
	}
}
