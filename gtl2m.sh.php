#!/usr/bin/php
<?php
require(__DIR__.'/inc/functions.inc.php');
require(__DIR__.'/inc/midi.class.php');

//don't buffer any output or time us out during execution..... we're a script with signal handling, not a webpage
ob_implicit_flush ();
set_time_limit (0);

//signal handling - signal_handler in functions.inc.php sets $must_exit to the name of the signal if detected
declare(ticks=1);
$must_exit=0;
pcntl_signal(SIGTERM, "signal_handler");
pcntl_signal(SIGINT, "signal_handler");

$separators="/\\";
$valid_notes="C,C#,D,D#,E,F,F#,G,G#,A,A#,B";
$notes="C,D,E,F,G,A,B"; //Cmaj scale
$durations="1,2,4,8,16,32,64"; //nd/th of a note
$channels="1,2,3,4,5,6,7,8,9,11,12,13,14,15,16";
$programs="1,5,9,13,17,21,25,32,36,44,48,56,64,72,80";
$octaves="2,3,4,5,6";
$framerates="25,30,60";
$timebase=480;
$bpm=120;
$framerate=25;
$duration=16;

$note_lookup=array_flip(explode(",","C,C#,D,D#,E,F,F#,G,G#,A,A#,B"));

//command line parameter passing
$argc=$_SERVER["argc"];
$argv=$_SERVER["argv"]; //$argv is an array

if($argc<2) error(usage());

$args=parse_args($argc,$argv);
if(isset($args['h']) or isset($args['help'])) error(usage());
$debug=0;
if(isset($args['debug'])) $debug=$args['debug'];

if(isset($args['duration'])) {
    $duration1=$args['duration'];
    if(!in_array($duration1,explode(",",$durations))) error("The note duration value is not valid!\n".usage());
    $duration=$duration1;
}

if(isset($args['i'])) $input=$args['i'];
if(isset($args['input'])) $input=$args['input'];
if(!isset($input) or strlen($input)==0) error("You must specify an input file!\n".usage());
if($input!=="-" and !file_exists($input)) error("Cannot find an input file with that name!\n".usage());

if(isset($args['o'])) $output=$args['o'];
if(isset($args['output'])) $output=$args['output'];
if(!isset($output) or strlen($output)==0) error("You must specify an output file!\n".usage());

if(isset($args['s'])) $separators=$args['s'];
if(isset($args['separators'])) $separators=$args['separators'];
$separator_list=array();
for($i=0;$i<strlen($separators);$i++) $separator_list[]=$separators[$i];

$framerate1=$framerate;
if(isset($args['f'])) $framerate1=$args['f'];
if(isset($args['framerate'])) $framerate1=$args['framerate'];
if(!in_array($framerate1,explode(",",$framerates))) error("You must specify a valid framerate!\n".usage());
$framerate=$framerate1;

$channels1=$channels;
if(isset($args['channels'])) $channels1=$args['channels'];
$channel_list=explode(",",$channels1);
foreach($channel_list as $c) {
    if($c<1 or $c>16) error("Invalid MIDI channels specified in the provided channel list!\n".usage());
}
$channels=$channels1;

$programs1=$programs;
if(isset($args['programs'])) $programs1=$args['programs'];
$program_list=explode(",",$programs1);
foreach($program_list as $p) {
    if($p<1 or $p>128) error("Invalid programs specified in the provided program list!\n".usage());
}
$programs=$programs1;

$octaves1=$octaves;
if(isset($args['octaves'])) $octaves1=$args['octaves'];
$octave_list=explode(",",$octaves1);
foreach($octave_list as $o) {
    if($o<0 or $o>7) error("Invalid octaves specified in the provided octave list!\n".usage());
}
$octaves=$octaves1;

$valid_note_list=explode(",",$valid_notes);
$notes1=$notes;
if(isset($args['notes'])) $notes1=$args['notes'];
$note_list=explode(",",$notes1);
foreach($note_list as $n) {
    if(!in_array($n,$valid_note_list)) error("Invalid notes specified in the provided note list!\n".usage());
}
$notes=$notes1;

function usage() {
    global $separators,$framerates,$framerate,$valid_notes,$notes,$durations,$duration,$channels,$tracks,$octaves,$programs;
    return "
usage: gtl2m.sh.php -i File -o File -r Framerate

Convert a Gource timing log to a MIDI file to enable adding sound to a Gource Video.

-i or --input      Gource Timing Log input file or - for stdin
-o or --output     Filename for the MIDI output file
-s or --separators A list of separator characters that we should use to split the filenames
                   with. Should be enclosed in double quotes. Defaults to ${separators}
-f or --framerate  Provide the framerate that the PPM output of gource was set to.
                   Valid values are ${framerates}. Defaults to ${framerate} frames per second.
--duration         Duration of MIDI notes in fractions of a beat (MIDI NoteOn to NoteOff period).
                   Valid values are ${durations}. Defaults to (1/)${duration} of a beat.
--channels         A list of MIDI channels to use in CSV format. Valid values are 1-16.
                   Defaults to ${channels}.
--programs         A list of program changes that each MIDI channel should use in CSV format.
                   Defaults to ${programs}.
--octaves          A list of the Octaves we can use in CSV format. Valid values are 0-7.
                   Defaults to ${octaves}.
--notes            A list of valid notes we are allowed to use in CSV format. Valid values are:
                   ${valid_notes}. Defaults to ${notes}.
--debug            Turns on debug output
-h or --help       Show this help :)
    ";
}

//we need the total number of users
//we need to split the file path down into 
//1) MIDI Notes (up to 12)
//2) MIDI Octaves (up to 5)
//3) MIDI channels (up to 16)

$beattime=($bpm/60)*$timebase; //the length of one beat in MIDI time.
$frametime=($bpm/60)/$framerate*$timebase; //length of one frame in MIDI time.
$start_offset=0; //($beattime*4); //start the notes (1 bar) in.

$time_offset=3; //in frames -to delay or pull forward the audio

$channel_count=count($channel_list);
$octave_count=count($octave_list);
$note_count=count($note_list);
$programs=array();
$t=1;
foreach($channel_list as $c) {
    $programs[$c]=$program_list[$t-1];
    $tracks[$c]=$t++;
}

$users=array();
$files=array();
$timingLogs = explode("\n",file_get_contents($input));
$max_log_time=0;
foreach($timingLogs as $tl) {
    if(false!==strpos(trim($tl),"|")) {
        $fields=explode("|",trim($tl));
        if(count($fields)) {
            if($fields[2]!='T') {
                if($fields[0]>$max_log_time) $max_log_time=$fields[0];
                if(!in_array($fields[1],$users)) $users[]=$fields[1];
                if(!in_array($fields[3],$files)) $files[]=$fields[3];
            }
        }
    }
    if($must_exit) die($must_exit);
}

$max_notes_per_channel=$note_count*$octave_count;

$midi_lookup=array();
foreach($files as $f) {
    $fr=str_replace($separator_list,"/",$f);
    $split_file=explode("/",$fr);
    array_shift($split_file); //first item is blank
    $split_count=count($split_file);
    if($split_count>$octave_count) $split_count=$octave_count;
    $octave=$octave_list[$split_count-1];
    $note=$note_list[rand(0,$note_count-1)];
    $channel=$channel_list[rand(0,$channel_count-1)];
    $midi_lookup[$f]=array('channel'=>$channel,'octave'=>$octave,'note'=>$note);
    if($must_exit) die($must_exit);
}

$event_list=array();
foreach($timingLogs as $tl) {
    if(false!==strpos(trim($tl),"|")) {
        $fields=explode("|",trim($tl));
        if(count($fields)) {
            $action=$fields[2];
            if($action=='T') {
                $time=round(($fields[0]+$time_offset)*$frametime+$start_offset);
                $user=$fields[1];
                $file=$fields[3];
                if($file!='') {
                    //time shift note-ons by 1/32 note if a note-on already exists
                    while(isset($event_list[$time]['note_on'])) $time=round($time+($beattime/32));
                    while(isset($event_list[$time]['note_off'])) $time+=1;
                    $event_list[$time]=array(
                        'time'=>$time,
                        'note_on'=>$note_lookup[$midi_lookup[$file]['note']]+($midi_lookup[$file]['octave']*12),
                        'channel'=>$midi_lookup[$file]['channel'],
                        'track'=>$tracks[$midi_lookup[$file]['channel']]
                    );
                    $time=round($time+($beattime/$duration));
                    while(isset($event_list[$time]['note_on'])) $time=round($time+($beattime/32));
                    while(isset($event_list[$time]['note_off'])) $time+=1;
                    $event_list[$time]=array(
                        'time'=>$time,
                        'note_off'=>$note_lookup[$midi_lookup[$file]['note']]+($midi_lookup[$file]['octave']*12),
                        'channel'=>$midi_lookup[$file]['channel'],
                        'track'=>$tracks[$midi_lookup[$file]['channel']]
                    );
                }
            }
        }
    }
    if($must_exit) die($must_exit);
}

$song_end=$time+$beattime;

ksort($event_list);
//if($debug) print_r($event_list);

foreach($channel_list as $c) {
    $track_xml[$tracks[$c]]=sprintf('
<Track Number="%s">
  <Event>
    <Absolute>0</Absolute>
    <ProgramChange Channel="%s" Number="%s"/>
  </Event>',$tracks[$c],$c,$programs[$c]);

    foreach($event_list as $t=>$e) {
        if($e['channel']==$c) {
            if(isset($e['note_on'])) {
                $track_xml[$tracks[$c]].=sprintf('
  <Event>
    <Absolute>%s</Absolute>
    <NoteOn Channel="%s" Note="%s" Velocity="%s"/>
  </Event>',$t,$c,$e['note_on'],100);
            }
            if(isset($e['note_off'])) {
                $track_xml[$tracks[$c]].=sprintf('
  <Event>
    <Absolute>%s</Absolute>
    <NoteOff Channel="%s" Note="%s" Velocity="%s"/>
  </Event>',$t,$c,$e['note_off'],0);
            }
        }
    }
    $track_xml[$tracks[$c]].=sprintf('
  <Event>
   <Absolute>%s</Absolute>
   <EndOfTrack/>
  </Event>
</Track>',$song_end);
}

$xml=sprintf('<?xml version="1.0" encoding="ISO-8859-1"?>
<!DOCTYPE MIDIFile SYSTEM "http://www.musicxml.org/dtds/midixml.dtd">
<MIDIFile>
<Format>1</Format>
<TrackCount>%s</TrackCount>
<TicksPerBeat>%s</TicksPerBeat>
<TimestampType>Absolute</TimestampType>
<Track Number="0">
  <Event>
    <Absolute>0</Absolute>
    <TimeSignature Numerator="4" LogDenominator="2" MIDIClocksPerMetronomeClick="24" ThirtySecondsPer24Clocks="8"/>
  </Event>
  <Event>
    <Absolute>0</Absolute>
    <KeySignature Fifths="0" Mode="0"/>
  </Event>
  <Event>
    <Absolute>0</Absolute>
    <SetTempo Value="%s"/>
  </Event>
  <Event>
    <Absolute>0</Absolute>
    <EndOfTrack/>
  </Event>
</Track>',count($track_xml),$timebase,round(60000000/$bpm));
foreach($channel_list as $c) {
    $xml.=$track_xml[$tracks[$c]];
}
$xml.='
</MIDIFile>';

if($debug) print $xml;

$midi = new Midi();
$midi->importXml($xml);		
$midi->saveMidFile($output, 0666);

exit;
//work out the best fit of channels/tracks/octaves and notes from the file names
$split_files=array();
$split_files_max_count=999;
foreach($files as $f) {
    $fr=str_replace($separator_list,"/",$f);
    $split_files[$fr]=explode("/",$fr);
    array_shift($split_files[$fr]); //first item is blank
    if(count($split_files[$fr])<$split_files_max_count) $split_files_max_count=count($split_files[$fr]);
    if($must_exit) die($must_exit);
}
printf("Maximum number of key splits available: %s\n",$split_files_max_count);

$key_count=0;
$channel_keys=array();
$keys=array();
while(count($keys)<$channel_count and $key_count<=$split_files_max_count) {
    $channel_keys=$keys;
    $keys=array();
    foreach($split_files as $k=>$v) {
        $key_index="";
        for($i=0;$i<$key_count;$i++) $key_index.="/".$v[$i];
        $j=strlen($key_index);
        $keys[$key_index][]=substr($k,$j);
        if($must_exit) die($must_exit);
    }
    printf("Split on %s = %s channels\n",$key_count,count($keys));  
    $key_count++;
}
$channel_keys_count=count($channel_keys);
$channel_keys_split=$key_count-2;
printf("Using Split on %s for %s MIDI channels\n",$channel_keys_split,$channel_keys_count);

printf("Maximum notes per MIDI channel = %s (%s notes x %s octaves)\n",$max_notes_per_channel,$note_count,$octave_count);

if($debug) {
    //print_r($files);
    //print_r($split_files);
    print_r($channel_keys);
    printf("Total Users: %s\n",count($users));

}
?>