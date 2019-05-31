<?php

function date_swap($dt) {
		return substr($dt,6,4).'-'.substr($dt,3,2).'-'.substr($dt,0,2);
}

function parse_args(&$argc,&$argv) {
		$argv[]="";
		$argv[]="";
		$args=array();
		//build a hashed array of all the arguments
		$i=1; $ov=0;
		while ($i<$argc) {
				if (substr($argv[$i],0,2)=="--") $a=substr($argv[$i++],2);
				elseif (substr($argv[$i],0,1)=="-") $a=substr($argv[$i++],1);
				else $a=$ov++;
				if (strpos($a,"=") >0) {
						$tmp=explode("=",$a);
						$args[$tmp[0]]=$tmp[1];
				} else {
						if (substr($argv[$i],0,1)=="-" or $i==$argc) $v=1;
						else $v=$argv[$i++];
						$args[$a]=$v;
				}
		}
		return $args;
}

function error($error) {
		print "$error\n";
		exit(1);
}

function signal_handler($signal) {
		global $must_exit;
		switch($signal) {
				case SIGTERM:
						$must_exit='SIGTERM';
						break;
				case SIGKILL:
						$must_exit='SIGKILL';
						break;
				case SIGINT:
						$must_exit='SIGINT';
						break;
				default:
						$must_exit="$signal";
						break;
		}
}

function get_data_from_url($url) {
		$ch = curl_init();
		$timeout = 5;
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
}

function check_data_preg($value,$preg,$default) {
	if(preg_match($preg,$value)) return $value; else return $default;
}

function multiExplode($delimiters,$string) {
    return explode($delimiters[0],strtr($string,array_combine(array_slice($delimiters,1),array_fill(0,count($delimiters)-1,array_shift($delimiters)))));
}
?>