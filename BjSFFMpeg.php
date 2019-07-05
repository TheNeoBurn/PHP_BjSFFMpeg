<?php

require_once('BjSGif.php');

class BjSFFMpeg {
	const FILE_FFMPEG_WIN = 'ffmpeg.exe';
	const FILE_FFMPEG_UNIX = 'ffmpeg';
	const FILE_FFPROBE_WIN = 'ffprobe.exe';
	const FILE_FFPROBE_UNIX = 'ffprobe';
	
	private static function FILE_FFMPEG() {
		return self::IsWin() ? self::FILE_FFMPEG_WIN : self::FILE_FFMPEG_UNIX;
	}
	private static function FILE_FFPROBE() {
		return self::IsWin() ? self::FILE_FFPROBE_WIN : self::FILE_FFPROBE_UNIX;
	}
	
	private static function IsWin() {
		return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
	}
	
	private static function CheckCommandExists($name) {
		$result = false;
		$cmd = self::IsWin() ? 'WHERE' : 'which';

		$proc = proc_open("$cmd $name", array(
				0 => array("pipe", "r"), //STDIN
				1 => array("pipe", "w"), //STDOUT
				2 => array("pipe", "w"), //STDERR
			), $pipes);
		if ($proc !== false) {
			$out = stream_get_contents($pipes[1]);
			$err = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($proc);

			$result = $out != '';
		}

		return $result;
	}
	
	private static function GetFullPath($name){
		$result = false;
		$cmd = self::IsWin() ? 'WHERE' : 'which';

		$proc = proc_open("$cmd $name", array(
				0 => array("pipe", "r"), //STDIN
				1 => array("pipe", "w"), //STDOUT
				2 => array("pipe", "w"), //STDERR
			), $pipes);
		if ($proc !== false) {
			$out = stream_get_contents($pipes[1]);
			$err = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($proc);

			$result = preg_replace('/[\r\n\t]/', '', $out);
		}

		return $result;
	}
	
	
	public static function Available() {
		return self::CheckCommandExists(self::FILE_FFMPEG()) && self::CheckCommandExists(self::FILE_FFPROBE());
	}
	
	/**
	 * Calls ffprobe to retrieve format and stream information and outputs it as a two dimensional
	 * array[area][name] = value
	 *
	 * @param string $filename    Local filename of a video
	 */
	public static function Probe($filename) {
		// Execute ffprobe command
		$cmd = self::FILE_FFPROBE() . " -hide_banner -v quiet -print_format ini -show_format -show_streams -i \"$filename\"";
		$out = [];
		exec($cmd, $out);
		
		// Read input as sorted array
		$ini = [];
		$ini[''] = [];
		foreach ($out as $line) {
			$line = trim($line);
			$len = strlen($line);
			if ($len < 3) { 
				// Not enough data in this line
			} else if (substr($line, 0, 1) === ';') { 
				// Comment line
			} else if ($len > 2 && substr($line, 0, 1) === '[' && substr($line, $len - 1, 1) === ']') { 
				// Group line
				$group = substr($line, 1, $len - 2);
				$ini[$group] = [];
			} else { 
				// Process name / value line
				$i = strpos($line, '=');
				if ($i !== false && $i > 0) {
					// Name and value found
					$name = substr($line, 0, $i);
					$value = ($len > $i + 1) ? substr($line, $i + 1) : '';
					$ini[$group][$name] = $value;
				}
			}
		}
		
		return $ini;
	}
	
	/**
	 * Calls ffprobe and returns specified values in an array
	 * 
	 * @param string $filename     Local filename of a video
	 * @returns array {
	 * 		int      width          The video width
	 * 		int      height         The video height
	 * 		string   video_codec    The video codec
	 * 		int      chanels        The number of audio chanels
	 * 		string   audio_codec    The audio codec
	 * 		string   container      The container information
	 *      double   duration       The duration in seconds
	 *      int      size           The file size
	 * }
	 */
	public static function ProbeLight($filename) {
		$ini = self::Probe($filename);
		
		$info = [];
		$cnt = 0;
		if (isset($ini['format'])) {
			// Go through streams
			if (isset($ini['format']['nb_streams'])) {
				$cnt = intval($ini['format']['nb_streams']);
				for ($i = 0; $i < $cnt; $i++) {
					if (isset($ini['streams.stream.' . $i])) {
						if (isset($ini['streams.stream.' . $i]['codec_type'])) {
							if ($ini['streams.stream.' . $i]['codec_type'] == 'video') {
								// Video stream
								if (isset($ini['streams.stream.' . $i]['width'])) {
									$info['width'] = intval($ini['streams.stream.' . $i]['width']);
								}
								if (isset($ini['streams.stream.' . $i]['height'])) {
									$info['height'] = intval($ini['streams.stream.' . $i]['height']);
								}
								if (isset($ini['streams.stream.' . $i]['codec_name'])) {
									$info['video_codec'] = $ini['streams.stream.' . $i]['codec_name'];
								}
							} else if ($ini['streams.stream.' . $i]['codec_type'] == 'audio') {
								// Audio stream
								if (isset($ini['streams.stream.' . $i]['channels'])) {
									$info['channels'] = intval($ini['streams.stream.' . $i]['channels']);
								}
								if (isset($ini['streams.stream.' . $i]['codec_name'])) {
									$info['audio_codec'] = $ini['streams.stream.' . $i]['codec_name'];
								}
							}
						}
					}
				}
			}
			
			// Get format info
			if (isset($ini['format']['format_name'])) {
				$info['container'] = $ini['format']['format_name'];
			}
			if (isset($ini['format']['duration'])) {
				$info['duration'] = doubleval($ini['format']['duration']);
			}
			if (isset($ini['format']['size'])) {
				$info['size'] = intval($ini['format']['size']);
			}
		}
		
		return $info;
	}
	
	/**
	 * Tries to extract a frame from the video at the timestamp
	 * 
	 * @param string $filename    A local video filename
	 * @param double $timestamp   A timestamp in seconds where to take the snapshot
	 * @returns string            A filename to a temporary filename where the snapshot is stored
	 *                            or null, if the call was unsuccessful
	 */
	public static function GetFrame($filename, $timestamp) {
		// Export frame to temporary file
		$tmpfile = tempnam(sys_get_temp_dir(), '.tmp_') . '.png';
		$cmd = self::FILE_FFMPEG() . " -ss $timestamp -i \"$filename\" -frames:v 1 \"$tmpfile\"";
		exec($cmd);
		
		// Return the temp filename
		return file_exists($tmpfile) ? $tmpfile : null;
	}
		
	/**
	 * Copy an image square cut and resized into another
	 * 
	 * @param ImageResource $target  The target image resource
	 * @param string $filename       The filename of the image to copy
	 * @param int $x                 The left coordinate of the image in the target
	 * @param int $y                 The top coordinate of the image in the target
	 * @param int $s                 The size of the image square in the target
	 */
	private static function CopyImageSquare($target, $filename, $x, $y, $s) {
		$source = imagecreatefrompng($filename);
		if ($source != null) {
			// Calculate source sizes
			$size = array(imagesx($source), imagesy($source));
			$sx = 0; // Source x-coordinate
			$sy = 0; // Source y-coordinate
			$ss = 0; // Source square size
			if ($size[0] < $size[1]) { // portrait
				$sy = (($s * $size[1] / $size[0]) - $s) / 2;
				$ss = $size[0];
			} else { // landscape 
				$sx = (($s * $size[0] / $size[1]) - $s) / 2;
				$ss = $size[1];
			}
			imagecopyresized($target, $source, $x, $y, $sx, $sy, $s, $s, $ss, $ss);
		}
		imagedestroy($source);
	}

	/**
	 * Creates a square annimated GIF containing snapshots in regular intervals
	 * 
	 * @param string $filename  A local video filename
	 * @param int    $size      The width (and height) in pixels
	 * @param int    $cnt       The number of snapshots to include
	 * @returns string          The file data of the annimated GIF
	 */
	public static function GetGifPreview($filename, $size = 100, $cnt = 9) {
		$cnt++;
		$info = self::ProbeLight($filename);
		$data = null;
		
		if (isset($info['duration'])) {
			set_time_limit(0);
			
			$duration = $info['duration'];
			
			$gif = new BjSGif(0);
			for ($i = 1; $i < $cnt; $i++) {
				$time = ($duration * $i) / $cnt;				
				$fname = self::GetFrame($filename, $time);
				if ($fname !== null) {
					// Create resized frame
					$img = imagecreatetruecolor($size, $size);
					self::CopyImageSquare($img, $fname, 0, 0, $size);
					
					// Draw movie border
					$r = intval($size / 15);
					$h = intval($r * 2 / 3);
					$b = intval(($r - $h) / 2);
					$c = imagecolorallocate($img, 0, 0, 0);
					imagefilledrectangle($img, 0, 0, $r, $size, $c);
					imagefilledrectangle($img, $size - $r, 0, $size, $size, $c);
					$c = imagecolorallocate($img, 255, 255, 255);
					$d = intval($size / ($cnt - 1));
					$y = $i - $d;
					for ($n = 0; $n < $cnt + 2; $n ++) {
						imagefilledrectangle($img, $b, $y, $b + $h, $y + $h, $c);
						imagefilledrectangle($img, $size - $h - $b, $y, $size - $b, $y + $h, $c);
						$y += $d;
					}
					
					// Make sure first image has transparent color
					if ($i == 0) { $c = imagecolortransparent($img); }
					
					$gif->Add($img, ($i === $cnt - 1) ? 200 : 50);
					
					imagedestroy($img);
					unlink($fname);
				}
			}
			
			$data = $gif->GetGifData();
		}
		
		return $data;
	}
}

?>
