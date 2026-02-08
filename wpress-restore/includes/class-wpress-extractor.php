<?php
/**
 * WPress archive extractor.
 *
 * Extracts .wpress files (All-in-One WP Migration format).
 * Format: 4377-byte header per file (filename 255, size 14, mtime 12, path 4096) + raw content.
 * EOF = 4377 null bytes.
 *
 * @package WPress_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPress_Extractor
 */
class WPress_Extractor {

	const HEADER_SIZE = 4377;

	const CHUNK_SIZE = 512000; // 512KB

	/**
	 * Header block format (unpack): filename, size, mtime, path.
	 *
	 * @var array
	 */
	protected $block_format = array(
		'a255',  // filename
		'a14',   // size
		'a12',   // mtime
		'a4096', // path
	);

	/**
	 * End-of-file block (4377 null bytes).
	 *
	 * @var string
	 */
	protected $eof;

	/**
	 * Archive file path.
	 *
	 * @var string
	 */
	protected $file_name;

	/**
	 * File handle for archive.
	 *
	 * @var resource|false
	 */
	protected $file_handle;

	/**
	 * Constructor.
	 *
	 * @param string $file_name Path to .wpress file.
	 */
	public function __construct( $file_name ) {
		$this->file_name = $file_name;
		$this->eof       = pack( 'a4377', '' );
	}

	/**
	 * Check if the archive is valid (ends with EOF block).
	 *
	 * @return bool
	 */
	public function is_valid() {
		$handle = @fopen( $this->file_name, 'rb' );
		if ( ! $handle ) {
			return false;
		}
		if ( @fseek( $handle, -self::HEADER_SIZE, SEEK_END ) !== 0 ) {
			fclose( $handle );
			return false;
		}
		$block = @fread( $handle, self::HEADER_SIZE );
		fclose( $handle );
		return $block === $this->eof;
	}

	/**
	 * Extract archive to a directory.
	 *
	 * @param string $destination Base path to extract into (e.g. temp dir). Files will be created with relative paths from the archive.
	 * @return array{ 'success': bool, 'extracted_paths': string[], 'message': string }
	 */
	public function extract_to( $destination ) {
		$destination = rtrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $destination ), DIRECTORY_SEPARATOR );
		if ( ! is_dir( $destination ) && ! wp_mkdir_p( $destination ) ) {
			return array(
				'success'          => false,
				'extracted_paths'  => array(),
				'message'          => __( 'Could not create extraction directory.', 'wpress-restore' ),
			);
		}

		$this->file_handle = @fopen( $this->file_name, 'rb' );
		if ( ! $this->file_handle ) {
			return array(
				'success'          => false,
				'extracted_paths'  => array(),
				'message'          => __( 'Could not open archive file.', 'wpress-restore' ),
			);
		}

		$extracted_paths = array();
		$real_dest       = realpath( $destination );
		if ( ! $real_dest ) {
			fclose( $this->file_handle );
			return array(
				'success'          => false,
				'extracted_paths'  => array(),
				'message'          => __( 'Invalid destination path.', 'wpress-restore' ),
			);
		}

		while ( true ) {
			$block = @fread( $this->file_handle, self::HEADER_SIZE );
			if ( strlen( $block ) !== self::HEADER_SIZE ) {
				break;
			}
			if ( $block === $this->eof ) {
				break;
			}

			$data = $this->get_data_from_block( $block );
			if ( ! $data ) {
				fclose( $this->file_handle );
				return array(
					'success'          => false,
					'extracted_paths'  => $extracted_paths,
					'message'          => __( 'Invalid header in archive.', 'wpress-restore' ),
				);
			}

			$filename = trim( $data['filename'] );
			$size     = (int) trim( $data['size'] );
			$path     = trim( $data['path'] );
			$path     = $path === '.' ? '' : $path;
			$full_name = $path ? $path . DIRECTORY_SEPARATOR . $filename : $filename;
			$full_name = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $full_name );
			$target    = $destination . DIRECTORY_SEPARATOR . $full_name;

			// Ensure target is under destination (no path traversal).
			$target_real = realpath( dirname( $target ) );
			if ( $target_real === false ) {
				$dir = dirname( $target );
				if ( ! wp_mkdir_p( $dir ) ) {
					@fseek( $this->file_handle, $size, SEEK_CUR );
					continue;
				}
				$target_real = realpath( $dir );
			}
			if ( ! $target_real || strpos( $target_real, $real_dest ) !== 0 ) {
				@fseek( $this->file_handle, $size, SEEK_CUR );
				continue;
			}

			$dir = dirname( $target );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				@fseek( $this->file_handle, $size, SEEK_CUR );
				continue;
			}

			$out = @fopen( $target, 'wb' );
			if ( ! $out ) {
				@fseek( $this->file_handle, $size, SEEK_CUR );
				continue;
			}

			$remaining = $size;
			while ( $remaining > 0 ) {
				$chunk = min( $remaining, self::CHUNK_SIZE );
				$data  = @fread( $this->file_handle, $chunk );
				if ( $data === false || strlen( $data ) !== $chunk ) {
					fclose( $out );
					fclose( $this->file_handle );
					return array(
						'success'          => false,
						'extracted_paths'  => $extracted_paths,
						'message'          => __( 'Archive read error or truncated file.', 'wpress-restore' ),
					);
				}
				@fwrite( $out, $data );
				$remaining -= $chunk;
			}
			fclose( $out );
			$extracted_paths[] = $full_name;
		}

		fclose( $this->file_handle );
		return array(
			'success'          => true,
			'extracted_paths'  => $extracted_paths,
			'message'          => '',
		);
	}

	/**
	 * Parse header block into filename, size, mtime, path.
	 *
	 * @param string $block 4377-byte block.
	 * @return array|false Associative array with filename, size, mtime, path or false.
	 */
	protected function get_data_from_block( $block ) {
		$format = $this->block_format[0] . 'filename/'
			. $this->block_format[1] . 'size/'
			. $this->block_format[2] . 'mtime/'
			. $this->block_format[3] . 'path';
		$data = @unpack( $format, $block );
		if ( ! $data || ! isset( $data['filename'], $data['size'] ) ) {
			return false;
		}
		return $data;
	}
}
