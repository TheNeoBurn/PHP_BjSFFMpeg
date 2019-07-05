<?php

/**
 * Simple direct GIF parts wrapper to create annimated GIF images from image frames in memory
 * 
 * @author NeoBurn (Bjørn Singer, neoburn@gmx.de)
 */
class BjSGif {
	/** @var int $Width            The width of the GIF */
	public $Width = 0;
	/** @var int $Height           The height of the GIF */
	public $Height = 0;
	/** @var int $ColorResolution  The used color resolution for the GIF (should be 7 but is automatically determined by Add()) */
	public $ColorResolution = 0;
	/** @var int $LoopCount        The number of annimation loops (0: unending) */
	public $LoopCount = 0;
	/** @var int $Parts            The image parts for the animation containing a GraphicsControl and LWZ image */
	public $Parts = [];
	
	public function __construct($loopCount, $width = 0, $height = 0) {
		$this->LoopCount = $loopCount;
		$this->Width = $width;
		$this->Height = $height;
	}
	
	/** 
	 * Adds a new image frame to the GIF
	 * 
	 * @param mixed $image  A image; can be image_resource, filename or raw data
	 * @param int   $delay  The time this frame should be shown before the next is
	 * @param int   $x      If not null, the left position of this frame is overwritten
	 * @param int   $y      If not null, the top position of this frame is overwritten
	 */
	public function Add($image, $delay = 20, $x = null, $y = null) {
		$part = null;
		
		// Unify to get GIF file data of the image
		$data = null;
		if (is_resource($image)) {
			// ImageResource => Save and get GIF file data
			ob_start();
			imagegif($image);
			$data = ob_get_contents();
			ob_end_clean();
		} else if (is_string($image)) {
			if (file_exists($image) || filter_var($image, FILTER_VALIDATE_URL)) {
				// Filename or URL => load file data
				$data = file_get_contents($image);                    
			}
			
			if (substr($data, 0, 3) != 'GIF') {
				// File data is not a GIF => Load image, save and get GIF file data
				$data = imagecreatefromstring($data);
				ob_start();
				imagegif($data);
				$content = ob_get_contents();
				ob_end_clean();
				$data = $content;
			}
		}
		
		// Check if image was loaded
		if ($data === null || substr($data, 0, 3) != 'GIF')
			throw new Exception('Could not load image as GIF file!');
		
		$dataLen = strlen($data);
		// Set a standard GraphicsControl part
		$gCtrl = "\x21\xF9\x04\x0C" . chr($delay & 0xFF) . chr(($delay & 0xFF00) >> 8) . "\x00\x00";
		$imgHead = '';
		$tableSize = 0;
		$tableSort = 0;
		$table = '';
		
		// Extract width
		$w = ord(substr($data, 6, 1)) | (ord(substr($data, 7, 1)) << 8);
		if ($this->Width < $w) $this->Width = $w;
		// Extract height
		$h = ord(substr($data, 8, 1)) | (ord(substr($data, 9, 1)) << 8);
		if ($this->Height < $h) $this->Height = $h;
		// Check for global color table
		$flags = ord(substr($data, 10, 1));
		// Extract ColorResolution
		$cr = ($flags & 0x70) >> 4;
		if ($cr > $this->ColorResolution) $this->ColorResolution = $cr;
		$pos = 13;
		if (($flags & 0x80) != 0) {
			// Cache global color table
			$tableSize = $flags & 0x07;             // Color table size raw
			$gsize = (1 << ($tableSize + 1)) * 3;   // Color table byte count
			$tableSort = ($flags & 0x08) >> 3;      // Color table sorted
			$table = substr($data, 13, $gsize);     // Color table data
			// Adjust position to skip global color table
			$pos += $gsize;
		}
		
		// Loop through parts
		$done = false;
		while (!$done && $pos < $dataLen) {
			$partType = ord(substr($data, $pos, 1));
			switch ($partType) {
				case 0x21: // Meta data part
					$start = $pos;
					$metaType = ord(substr($data, $pos + 1, 1));
					// Skip meta part code (0x21) and meta data sub-type code
					$pos += 2;
					// Skip data (length code + data[length] until length code is 0)
					$len = 0;
					do {
						$len = ord(substr($data, $pos, 1)); // Read length
						$pos += 1 + $len;                   // Skip length code and data[length]
					} while ($len > 0);
					
					// If this is a GraphicsControl part, select it
					if ($metaType == 0xF9) {
						$gCtrl = substr($data, $start, $pos - $start);
					}
					break;
					
				case 0x2C: // LWZ image data part
					$imgData = "\x2C";
					// Set position
					$imgData .= ($x !== null) ? (chr($x & 0xFF) . chr(($x & 0xFF00) >> 8)) : substr($data, $pos + 1, 2);
					$imgData .= ($y !== null) ? (chr($y & 0xFF) . chr(($y & 0xFF00) >> 8)) : substr($data, $pos + 3, 2);
					// Set size
					$imgData .= substr($data, $pos + 5, 4);
					$pos += 9;
					
					// Check for local color table
					$flags = ord(substr($data, $pos, 1));
					$pos += 1;
					if (($flags & 0x80) != 0) { // found
						$imgData .= chr($flags);               // Copy flags
						$tableSize = $flags & 0x07;            // Color table size raw
						$gsize = (1 << ($tableSize + 1)) * 3;  // Color table byte count
						// Extract local color table
						$imgData .= substr($data, $pos, $gsize); 
						// Skip local color table
						$pos += $gsize;
					} else { // not found => use global
						// Set new flags (0x80:LocalColorTable, 0x40:Interlaced, 0x20:TableSorted, 0x07:TableSize)
						$imgData .= chr(0x80 | ($flags & 0x40) | ($tableSort ? 0x20 : 0x00) | $tableSize);
						// Append global color table
						$imgData .= $table;
					}
					
					// Append MinimalCodeSize
					$imgData .= substr($data, $pos, 1);
					$pos++;
					
					// Collect image data
					$start = $pos;
					do {
						$len = ord(substr($data, $pos, 1)); // Read length
						$pos += 1 + $len;                   // Skip length code and data[length]
					} while ($len > 0);
					// Append image data
					$imgData .= substr($data, $start, $pos - $start);
					// Finish processing
					$done = true;
					break;
					
				case 0x3B: // Termination
					$done = true;
					break;
					
				default:
					throw new Exception('Unknown part type: ' . $partTpye);
					$done = true;
					break;
			}
		}
		
		$this->Parts[] = $gCtrl . $imgData;
	}
	
	/**
	 * Concats the parts into a GIF container
	 * 
	 * @returns string 
	 */
	public function GetGifData() {
		// Create GIF header
		$data = "GIF89a";
		$data .= chr($this->Width & 0xFF) . chr(($this->Width & 0xFF00) >> 8);   // Width
		$data .= chr($this->Height & 0xFF) . chr(($this->Height & 0xFF00) >> 8); // Height
		$data .= chr(($this->ColorResolution & 0x07) << 4);                      // Flags
		$data .= chr(0); // Background color
		$data .= chr(0); // AspectRatio
		
		// Append Application meta part (for annimation)
		$data .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01" . chr($this->LoopCount & 0xFF) . chr(($this->LoopCount & 0xFF00) >> 8) . "\x00";
		
		// Append parts
		foreach ($this->Parts as $part) {
			$data .= $part;
		}
		
		// Append termination
		$data .= chr(0x3B);
		
		return $data;
	}
}

?>