<?php

/**
 * Small php class for steal images from swf
 * Created: 2012-07-01
 * Craated by Yaroslav O Golub
 * All copyleft.
 */

define('HEADER_TAG', -1);
define('DEFINE_BITS', 6);
define('JPEG_TABLES', 8);
define('DEFINE_BITS_LOSSLESS', 20);
define('DEFINE_BITS_JPEG2', 21);
define('DEFINE_BITS_JPEG3', 35);
define('DEFINE_BITS_LOSSLESS2', 36);
define('DEFINE_BITS_JPEG4', 90);


define('BIT_8', 3);

class SWF_ripper {

	private $swf_name = '';
	private $data;
	private $position = 0;
	private $length;
	private $_metadata;

	private $_bitPosition = 8;
	private $_bitBuffer = null;

	private $_tags = array();

	private $_jpeg_table = false;

	private $_images;

	public function __construct($swf_file) {
		try {
			if ( substr($swf_file, -4) != '.swf') {
				throw new Exception("SWF ripper: file format not support");
			}
			$this->swf_name = basename($swf_file, '.swf');

			if (!file_exists( $swf_file)){
				throw new Exception(sprintf('file %s not found', $swf_file));
			}

			$this->data = file_get_contents($swf_file);
			if (!$this->data) {
				throw new Exception(sprintf('file %s is bad', $swf_file));
			}

			$this->length = strlen($this->data);

			$this->_metadata['signature'] = $this->readStr(3);
			$this->_metadata['version']  = $this->readI8();
			$this->_metadata['length']  =  $this->readI32();

			if ($this->_metadata['signature'] == 'CWS') {
				// zipped
				$this->_metadata['is_commpressed'] = true;
				if (!$this->deflate()) {
					throw new Exception("SWF ripper: Error of decompress");
				}
			} else {
					throw new Exception("SWF ripper: other types not support");
			}
			$this->_metadata['frameSize']  =  $this->readRect();
			$this->_metadata['frameRate']  =  $this->readI16() / 256;
			$this->_metadata['frameCount']  = $this->readI16();
			$this->readTags();

		} catch ( Exception $e )	{
			/* TODO exception*/
		}
	}

	private function deflate() {
		$gzdata = substr($this->data, $this->position + 2);
		$gzdata = gzinflate($gzdata);
		if ($gzdata) {
			$this->data = substr($this->data, 0,  $this->position) . $gzdata;
			return true;
		} else {
			return false;
		}
	}

	private function unzip($data){
		//2 - size of ZLIB metadata
		$gzdata = substr($data, 2);
		return gzinflate($gzdata);
	}

	private function alignBits(){
		$this->_bitPosition = 8;
		$this->_bitBuffer = null;
	}

	private function seek($len) {
		$this->position += $len;
		$this->alignBits();
	}

	private function readStr($number_byte) {
		$ret = substr($this->data, $this->position, $number_byte);
		$this->position += strlen($ret);
		return $ret;
	}

	private function readInt($numBytes, $signed = false){
		$val = 0;
		$ret = substr($this->data, $this->position, $numBytes);
		$this->position += strlen($ret);
		for($i=$numBytes; $i>=0; $i--){
			$val = $val << 8 | ord($ret[$i]);
		}
		$this->alignBits;
		if ($signed) {
			$numBits = $numBytes * 8;
			if ($val >> ($numBits - 1)) $val -= pow(2, $numBits);
		}
		return $val;
	}

	private function readI8() {
		return $this->readInt(1);
	}

	private function readI16() {
		return $this->readInt(2);
	}

	private function readI32() {
		return $this->readInt(4);
	}

	private function readSI8() {
		return $this->readInt(1, true);
	}

	private function readSI16() {
		return $this->readInt(2, true);
	}

	private function readSI32() {
		return $this->readInt(4, true);
	}


	private function readBits($numBits, $lsb = false){
		$val = 0;
		for( $i = 0; $i < $numBits; $i++) {
			if (8 == $this->_bitPosition) {
				$this->_bitBuffer = $this->readI8();
				$this->_bitPosition = 0;
			}
			if ($lsb) {
				$val |= ($this->_bitBuffer & (0x01 << $this->_bitPosition++) ? 1 : 0) << $i;
			} else {
				$val = ($val << 1) | ($this->_bitBuffer & (0x80 >> $this->_bitPosition++) ? 1 : 0);
			}
		}
		return $val;
	}

	private function readSBits($numBits) {
		$val = $this->readBits($numBits);		
		$shift = 32 - $numBits;
		$result = ($val << $shift) >> $shift;
		return $result;
	}

	private function readFixedPoint($numBits, $precision) {
		return $this.readSBits($numBits) * pow(2, -$precision);
	}

	function readFixed() {
		return $this->readFixedPoint(32, 16);
	}

	function readFixed8() {
		return $this->readFixedPoint(16, 8);
	}

	function readFB($numBits) {
		return $this->readFixedPoint($numBits, 16);
	}

	private function readRect() {
		$numBits = $this->readBits(5);
		$rect = array(
			"left" => $this->readSBits($numBits),
			"right" => $this->readSBits($numBits),
			"top" => $this->readSBits($numBits),
			"botom" => $this->readSBits($numBits)
		);
		$this->alignBits();
		return $rect;
	}

	private function readTagHeader(){
		if ($this->position > $this->length ) {
			return false;
		}

		$pos = $this->position;
		$tag = array();
		$tagTypeAndLength = $this->readI16();

		$tag['contentLength'] = ($tagTypeAndLength & 0x003F);

		// Long header
		if ($tag['contentLength'] == 0x3F) {
			$tag['contentLength'] =  $this->readSI32();
		}

		$tag['type'] =  $tagTypeAndLength >> 6;
		$tag['headerLength'] = $this->position - $pos;
		$tag['tagLength'] = $tag['headerLength'] + $tag['contentLength'];
		$tag['position']  = $pos . ' - ' . $this->position;
		return $tag;
	}

	private function skipTag($tag) {
		if (!$tag  || !$tag['contentLength']) {
			return false;
		}
		$this->seek($tag['contentLength']); // Skip bytes
	}

	private function readTags() {
		$tag = $this->readTagHeader();
		while($tag) {
			/* only images, but mechanism is equal for other types */
			switch ($tag['type']) {
				case JPEG_TABLES:
					$this->readJpegTables($tag);
				break;
				case DEFINE_BITS:
					$img = $this->readDefineBits($tag);
					$this->_images[] = $img;
				break;
			    case DEFINE_BITS_JPEG2:
					$img = $this->readDefineBits($tag);
					$img['tag'] = 'defineBitsJPEG2';
					$this->_images[] = $img;
				break;
				case DEFINE_BITS_JPEG3:
					$img = $this->readDefineBits($tag, true);
					$this->_images[] = $img;
				break;
				case DEFINE_BITS_JPEG4:
					$img = $this->readDefineBits($tag, true, true);
					$this->_images[] = $img;
				break;
				case DEFINE_BITS_LOSSLESS:
					$img = $this->readDefineBitsLossless($tag);
					$this->_images[] = $img;
				break;
				case DEFINE_BITS_LOSSLESS2:
					$img = $this->readDefineBitsLossless($tag, true);
					$this->_images[] = $img;
				break;
				default:
					$this->skipTag($tag);
				break;
			}
			$this->_tags[] = $tag;
			$tag = $this->readTagHeader();
		}
	}

	private function readJpegTables($tag){
		$this->_jpeg_table = $this->readStr($tag['contentLength']);
	}


	/* workaround jpeg */
	function readDefineBits($tag, $withAlpha = false, $withDeblock = false) {

		$id = $this->readI16();
		$alphaDataOffset = 0;

		$img = array(
			'type' => 'image',
			'id' => $id,
			'imageType' => withAlpha ? "PNG" : "JPEG",
			'width' => 0,
			'height' => 0
		);

		if ($withAlpha) $alphaDataOffset = $this->readI32();
		if ($withDeblock) $img['deblockParam'] = $this->readFixed8();
		if ($withAlpha) {
			$data = $this->readStr($alphaDataOffset);
			// transparency
			$alphaData = $this->readStr($tag['contentLength'] - $alphaDataOffset - 6);
			$img['alphaData'] = $this->unzip($alphaData);
		} else {
			$data = $this->readStr($tag['contentLength'] - 2);
			// Before version 8 of the SWF file format, SWF files could contain an erroneous header of 0xFF, 0xD9, 0xFF, 0xD8 before the JPEG SOI marker.
			if ($data[0] == 0xFF && $data[1] == 0xD9 && $data[2] == 0xFF &&$data[3] == 0xD8) {
				$data = substr($data, 4);
			}
		}
		// size
		for ($i = 0; $i < strlen($data); $i++) {
			$word = ((ord($data[$i]) & 0xff) << 8) | (ord($data[++$i]) & 0xff);
			if (0xffd9 == $word) {
				$word = ((ord($data[++$i]) & 0xff) << 8) | (ord($data[++$i]) & 0xff);
				if($word == 0xffd8){
					$data = substr($data, 0, $i - 4) . substr($data, $i);
					$i -= 4;
				}
			} else if (0xffc0 == $word) {
				$i += 3;
				$img['height'] = ((ord($data[++$i]) & 0xff) << 8) | (ord($data[++$i]) & 0xff);
				$img['width'] = ((ord($data[++$i]) & 0xff) << 8) | (ord($data[++$i]) & 0xff);
				break;
			}
		}

		if (!empty($this->_jpeg_table)) {
			$img['data'] = substr($this->_jpeg_table, 0, strlen($this->_jpeg_table) - 2) . $substr($data, 2);
		} else {
			$img['data'] = $data;
		}

		if ($withAlpha && $withDeblock) {
			$img['tag'] = 'defineBitsJPEG4';
		} else if ($withAlpha) {
			$img['tag'] = 'defineBitsJPEG3';
		} else {
			$img['tag'] = 'defineBits';
		}

		return $img;
	}

	 /* BMP */
	function readDefineBitsLossless($tag, $withAlpha = false) {

		$img = array();
		$img['type'] = 'image';
		$img['id'] = $this->readI16();
		$img['format'] = $this->readI8();
		$img['width'] = $this->readI16();
		$img['height'] = $this->readI16();
		$img['withAlpha'] = $withAlpha;
		$img['imageType'] = $img['format'] != 3 ? "PNG" : "GIF89a";
		$img['tag'] = $withAlpha ? 'defineBitsLossless2' : 'defineBitsLossless';

		if ($img['format'] == BIT_8) $img['colorTableSize'] = $this->readI8() + 1;

		$zlibBitmapData = $this->readStr($tag['contentLength'] - (($img['format'] == BIT_8) ? 8 : 7));
		$img['data'] = $this->unzip($zlibBitmapData);
		$img['size'] = strlen($img['data']);		
		return $img;

	}
	/**
	 * Function by export
	 * @param type $img - ripped images
	 * @param type $file_name - if need save image to file, else will be output to stdout
	 * @param type $mirror - mirror image
	 * @param type $crop - crop image
	 * @return boolean
	 */
	private function exportImage($img, $file_name = false, $mirror = false, $crop = false) {
	
		if (!$img['data']) return false;
		$image = imagecreatefromstring($img['data']);
		imagesavealpha($image, true);
		imagealphablending($image, false);

		$w = imagesx($image);
		$h = imagesy($image);

		$from_x = $w;
		$to_x = 0;
		$from_y = $h;
		$to_y = 0;
		
		if ($crop) {
			foreach(range(0, $h) as $y){
				foreach(range(0, $w) as $x) {
					if (isset($img['alphaData'])) {						
						$alpha = 127 - floor(ord($img['alphaData'][$y * $w + $x ]) / 2 );
						if ($alpha <= 0 ) $alpha = 0;
					} else {
							$alpha = 0;
					}
					if ( $alpha == 0) {
						$from_x = min($from_x, $x);
						$from_y = min($from_y, $y);
						$to_x = max($to_x, $x);
						$to_y = max($to_y, $y);
					}
				}
			}
			if ($from_x > 2) $from_x -= 2;
			if ($to_x < $w - 2) $to_x += 2;
			if ($from_y > 2) $from_y -= 2;
			if ($to_y < $h - 2) $to_y += 2;
		}

		
		$dest = imagecreatetruecolor($to_x - $from_x, $to_y - $from_y);
		imagesavealpha($dest, true);
		imagealphablending($dest, false);
		
		foreach(range($from_y, $to_y) as $y){
			foreach(range($from_x, $to_x) as $x) {
				$rgb = imagecolorat($image, $x, $y);				
				$r = ($rgb >> 16) & 0xFF;
				$g = ($rgb >> 8) & 0xFF;
				$b = $rgb & 0xFF;
				if (isset($img['alphaData'])) {
					$alpha = 127 - floor(ord($img['alphaData'][$y * $w + $x ]) / 2 );
					if ($alpha < 0 ) $alpha = 0;
				} else {
					$alpha = 0;
				}
				$rgba = imagecolorallocatealpha($dest, $r, $g, $b, intval($alpha));
				imagesetpixel($dest, $mirror ? ($to_x - $from_x) - ($x - $from_x) : $x - $from_x , $y - $from_y, $rgba);
			}
		}	
		if ($file_name) {
			imagepng($dest, $file_name);
		} else {
			header("Content-Type: image/png");
			imagepng($dest);
		}
	}

	public function exportAllImages($path, $mirror = false, $crop = false){
		$num = 0;
		if (!$this->_images) return false;
		foreach ( $this->_images as $img ) {						
			$filename = $this->swf_name . ($num++) . '.png' ;
			$this->exportImage($img, $path . '/' . $filename, $mirror, $crop);
		}
		return true;
	}
}

/** 
Example:

$my_swf = new SWF_ripper('path_to.swf');
$my_swf->exportAllImages('./path_to_image_storage/');
 
 */

?>