<?php
namespace dynoser\base128;

class vc128
{
    public static $vc128enc = []; // [int num128] => char (utf)
    public static $vc128dec = []; // [int char-code] => int num 0-127
    public static $vc128chars = ''; // all available bytes for decoding

    // encoder options:
    public static $currentEncodeMode = 0; // encode to: 2 (utf) or 1 (cp1251)
    public static $splitWidth = 80; // split result to lines by width
    public static $addPf = true; // add `{ ... } or not (for encode128 method)
    
    public static $mbstrsplit = null; // true if mb_str_split function is available
    
    public static $pow128Arr = [562949953421312, 4398046511104, 34359738368, 268435456, 2097152, 16384, 128, 1];

    /**
     * encode mode:
     *  2 (default) - encode to utf-8  (1 or 2 bytes per char)
     *  1           - encode to cp1251 (1 byte per char)
     * @param int $encodeMode 1 or 2
     */
    public static function init($encodeMode = 0) {
        $encodeMode || $encodeMode = self::$currentEncodeMode ?: 2;
        if (self::$currentEncodeMode == $encodeMode) {
            return;
        }

        self::$mbstrsplit = \function_exists('\mb_str_split');
        
        // Compiling charmap: 85 ascii + 42 ciryllic chars + |
        $repc = 118;
        for($i = 0; $i < 85; $i++) {
            $charNum = \in_array($i, [1, 6, 58, 59, 60]) ? $repc++ : (33 + $i);
            self::$vc128dec[$charNum] = $i;
            self::$vc128enc[$i] = \chr($charNum);
        }
        // добавляем только те кириллические символы, для которых отсутствуют визуально-совпадающие символы в латинице:
        $vcArr = \explode(' ', "Б в Г г Д д Ж ж з И и Й й к Л л м н П р т Ф ф Ц ц Ч ч Ш ш Щ щ Ъ ъ Ы ы ь Э э Ю ю Я я |");
        // не хватает ёЁ, заменяются б на 6, п на n, У на Y, остальные кирилличные символы визуально идентичны латинице
        // символы в коде utf-8 (поэтому сам файл с php-кодом должен быть в utf-8). Последний символ однобайтовый.
        $cp1251 = \hex2bin('c1e2c3e3c4e4c6e6e7c8e8c9e9eacbebecedcff0f2d4f4d6f6d7f7d8f8d9f9dafadbfbfcddfddefedfff00');
        for(; $i < 128; $i++) {
            $p = $i - 85;
            $charEnc = $vcArr[$p];
            $charNum = \ord(\substr($charEnc, -1));
            $cp1251ch = $cp1251[$p];
            $cp1251num = \ord($cp1251ch);
            self::$vc128dec[$charNum] = $i;
            if ($cp1251num) {
                self::$vc128dec[$cp1251num] = $i;
                if ($encodeMode == 1) {
                    $charEnc = $cp1251ch;
                }
            }
            self::$vc128enc[$i] = $charEnc;
        }
        
        // put all available chars to one string (from array keys to ascii-chr)
        self::$vc128chars = \implode('', \array_map('chr',\array_keys(self::$vc128dec)));
        
        self::$currentEncodeMode = $encodeMode;
    }
    
    /**
     * Encode string to vc128 (or mix-encode)
     * 
     * @param string $str
     * @param bool $useMix true = mix-encode, false = raw encode128 (default)
     * @return string
     */
    public static function encode($str, $useMix = false) {
        self::$addPf = true;
        return $useMix ? self::encodeMix($str) : self::encode128($str);
    }
    
    /**
     * Decode string from vc128 (raw or mix)
     *
     * @param string $dataStr
     * @return string
     */
    public static function decode($dataStr) {
        return (false === strpos($dataStr, '`{')) ? self::decode128($dataStr) : self::decodeMix($dataStr);
    }
    
    /**
     * Decode mix-encoded string (decode vc128 from `{...} insertions)
     * 
     * @param string $dataStr
     * @return string
     */
    public static function decodeMix($dataStr) {
        return \preg_replace_callback('/`{(.*?)}/s', function ($match) {
            return self::decode128($match[1]);
        }, $dataStr);
    }

    /**
     * Encode binary-string data to base-128
     * use:
     *   vc128::$splitWidth (to split result to lines by width, set 0 if no need)
     *   vc128::$addPf (true or false, will add `{ ... } or not)
     * @param string $dataStr binary string
     * @return string vc128 encoded string
     */
    public static function encode128($dataStr) {
        $l = \strlen($dataStr);
        $sub = $l % 7;
        $pad = $sub ? (7 - $sub) : 0;
        
        self::$currentEncodeMode || self::init();
        $enc128Arr = self::$vc128enc;
  
        $out = self::$addPf ? ['`{'] : [];
        if ($l) {
            $x0 = \chr(0);
            foreach(\str_split($dataStr . \str_repeat($x0, $pad), 7) as $g7str) {
                $uint64 = \unpack('J', $x0 . $g7str);
                $tmp = $uint64[1];
                $sum = '';
                
                // encoding by pow128Arr
                foreach(self::$pow128Arr as $pow) {
                    $sum .= $enc128Arr[(int)($tmp / $pow)];
                    $tmp %= $pow;
                }
                // encoding by bit-shifting, but it bit slower in my tests
//                for ($i = 0; $i < 8; $i++) {
//                    $sum = $enc128Arr[$tmp & 127] . $sum;
//                    $tmp = $tmp >> 7;
//                }
                $out[] = $sum;
            }
        }
        if ($sub) {
            $out[\count($out)-1] = (self::$currentEncodeMode > 1) ?
                    \implode('', \array_slice(self::explodeUTF8($sum), 0, 8 - $pad))
                  : \substr($sum, 0, -$pad);
        }
        if (self::$addPf) {
            $out[] = '}';
        }
        return (self::$splitWidth > 0) ? self::implodeSplitter($out) : \implode('', $out);
    }
    
    private static function implodeSplitter($out) {
        if (self::$mbstrsplit) {
            $arr = \mb_str_split(\implode('', $out), self::$splitWidth, 'utf-8');
        } else {
            $arr = [];
            $rowLen = 0;
            $st = '';
            foreach($out as $grp8) {
                $st .= $grp8;
                if ($rowLen < self::$splitWidth) {
                    $rowLen += 8;
                } else {
                    $arr[] = $st;
                    $st = '';
                    $rowLen = 0;
                }
            }
            if ($rowLen) {
                $arr[] = $st;
            }
        }

        return \implode("\n", $arr);
    }
    
    /**
     * Decodes all base128 encoding variants, has no options
     *
     * @param string $dataSrc base-128 encoded string
     * @return string decoded binary data
     * @throws InvalidArgumentException
     */
    public static function decode128($dataSrc) {
        $data = \trim(\strtr($dataSrc, \chr(208) . \chr(209) . "\t\n\r", '     '));
        if (\substr($data, 0, 2) === '`{' && \substr($data, -2) === '}') {
            $data = \substr($data, 2, -1);
        } else {
            // try cut data between \{ ... }
            $p = \strpos($data, '`{');
            $i = (false === $p) ? 0 : $p + 2;
            $j = \strpos($data, '}', $i);
            if ($j) {
                $data = \substr($data, $i, $j - $i);
            } elseif ($i) {
                $data = \substr($data, $i);
            }
        }
       
        self::$currentEncodeMode || self::init();

        $dataWrk = \str_replace(" ", '', $data);

        $l = \strlen($dataWrk);
        if (!$l) {
            return '';
        }
        if ($l !== \strspn($dataWrk, self::$vc128chars)) {
            throw new \InvalidArgumentException("Data contains invalid characters");
        }
        $sub = $l % 8;
        $pad = $sub ? (8 - $sub) : 0;

        $out = \array_map(function($value) {
            $sum = 0;
            foreach (\unpack("C*", $value) as $char) {
                $sum = ($sum << 7) + self::$vc128dec[$char];
            }
            return \substr(\pack('J', $sum), -7);
        }, \str_split($dataWrk . \str_repeat(self::$vc128enc[127], $pad), 8));

        if ($sub) {
            $lg = \count($out) - 1;
            $out[$lg] = \substr($out[$lg], 0, 7 - $pad);
        }

        return \implode('', $out);
    }
    
    /**
    * Splits a UTF-8 string into characters.
    *
    * This function takes a UTF-8 encoded string as input and breaks it down into its constituent characters.
    * If invalid bytes are encountered, returns an integer with the position of those bytes
    *
    * @param string $str The input string to be split.
    * @param int $brkCnt The maximum number of characters to break the string into. Defaults -1 (whole string).
    * @param int $fromByte The byte position in the string at which parsing begins. Defaults 0.
    *
    * @return array|int An array of characters or int in case of an error.
    */
    public static function explodeUTF8($str, $brkCnt = -1, $fromByte = 0) {
        if (self::$mbstrsplit && $brkCnt < 0) {
            return \mb_str_split(\substr($str, $fromByte), 1, 'utf-8');
        }
        $charsArr = [];
        $len = \strlen($str);
        if ($brkCnt < 0) {
            $brkCnt = $len;
        }
        for($i = $fromByte; $i < $len; $i++){
            $cn = \ord($str[$i]);
            if ($cn > 128) {
                if (($cn > 247)) return $i;
                elseif ($cn > 239) $bytes = 4;
                elseif ($cn > 223) $bytes = 3;
                elseif ($cn > 191) $bytes = 2;
                else return $i;
                if (($i + $bytes) > $len) return $i;
                $charsArr[] = \substr($str, $i, $bytes);
                while ($bytes > 1) {
                    $i++;
                    $b = \ord($str[$i]);
                    if ($b < 128 || $b > 191) return $i;
                    $bytes--;
                }
            } elseif ($cn > 31 || $cn == 13 || $cn == 10) {
                $charsArr[] = \chr($cn);
            } else {
                return $i;
            }
            if ($i > $brkCnt) break;
        }
        return $charsArr;
    }

    public static function tryExplode($str, $brkCnt = -1, $fromi = 0) {
        $result = self::explodeUTF8($str, $brkCnt, $fromi);
        $goodPart = \is_array($result) ? \implode('', $result) : \substr($str, $fromi, $result - $fromi);
        $i = \strpos($goodPart, '`{');
        if (false !== $i) {
            $result = $fromi + $i;
        }
        return $result;
    }

    public static function encodeMix($str) {
        self::$addPf = true;
        if (self::$currentEncodeMode == 1) {
            return self::encode128($str);
        }
        $intervArr = [];
        $ic = 0;
        $contI = 0;
        $l = \strlen($str);
        for ($p = 0; $p < $l; $p = $contI) {
            $result = self::tryExplode($str, $l, $p);
            if (\is_array($result)) {
                $intervArr[] = [0, $p, 0]; //\substr($str, $p);
                $ic++;
                break;
            }
            $goodLen = $result - $p;
            if ($goodLen) {
                if (!$ic || $goodLen > 7) {
                    $intervArr[] = [0, $p, $goodLen];
                    $ic++;
                } else {
                    // expand previous interval for encode
                    $intervArr[$ic - 1][2] += $goodLen;
                }
                $p += $goodLen;
            }
            
            $maxI = 0;
            $maxV = 0;
            for($v = 1; $v < 8; $v++) {
                $result = self::tryExplode($str, $p + 256, $p + $v);
                if (\is_array($result)) {
                    break;
                }
                if ($result > $maxI) {
                    $maxI = $result;
                    $maxV = $v;
                }
            }
            
            if (\is_array($result)) {
                $contI = $p + $v;
            } else {
                $contI = $p + $maxV;
            }
            $badStr = \substr($str, $p, $contI - $p);
            if ($ic && $intervArr[$ic - 1][0]) {
                // expand previous interval for encode
                $intervArr[$ic - 1][2] += $contI - $p;
            } else {
                // create new interval for encode
                $intervArr[] = [1, $p, $contI - $p]; //self::encode128($badStr);
                $ic++;
            }
        }
        
        foreach($intervArr as $n => $el) {
            $part = $el[2] ? \substr($str, $el[1], $el[2]) : \substr($str, $el[1]);
            $intervArr[$n] = $el[0] ? self::encode128($part) : $part;
        }
        
        return \implode('', $intervArr);
    }

    public static function outTable($colDiv = '|') {
        self::init();
        $colWidth = 10;
        $strWitdh = 8 * $colWidth;
        $outrows = \array_fill(0, 16, $colDiv . \str_repeat(' ', $strWitdh));
        $rown = 0;
        $coln = 0;
        for($n = 0; $n < 128; $n++) {
            $nstr = \sprintf("%' 3d", $n);
            $basech = self::$vc128enc[$n];
            $colpos = $coln * $colWidth + 1;
            $newstr = $outrows[$rown];
            $left = $colpos ? \substr($newstr, 0, $colpos) : '';
            $newstr = $left . $nstr . "  $basech $colDiv ";
            $outrows[$rown] = $newstr;
            $rown = ($rown > 14) ? (0 * $coln++) : ($rown + 1);
        }
        $border = str_repeat('-', 72);
        array_unshift($outrows, $border);
        $outrows[] = $border;
        return \implode("\n", $outrows) . "\n";
    }
}
