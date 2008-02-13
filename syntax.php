<?php
/**
 * DokuWiki Syntax Plugin GTD (Getting Things Done)
 * 
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Michael Klier <chi@chimeric.de>
 */
// must be run within DokuWiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

if(!defined('DOKU_LF')) define('DOKU_LF',"\n");
 
/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_gtd extends DokuWiki_Syntax_Plugin {
 
    /**
     * return some info
     */
    function getInfo(){
        return array(
            'author' => 'Michael Klier',
            'email'  => 'chi@chimeric.de',
            'date'   => '2008-02-13',
            'name'   => 'GTD (Getting Things Done)',
            'desc'   => 'Implements a ToDo List following the principles of GTD.',
            'url'    => 'http://www.chimeric.de/projects/dokuwiki/plugin/gtd',
        );
    }
 
    function getType()  { return 'substition'; }
    function getPType() { return 'block'; }
    function getSort()  { return 320; }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) { $this->Lexer->addSpecialPattern('<gtd.*?>.+?</gtd>',$mode,'plugin_gtd'); }
 
    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler) {

        // check for modified expiries
        if(preg_match("#<gtd(.*?)>#", $match, $params)) {
            if(!empty($params[1])) {
                $expiries = array();
                if(preg_match_all("#((warn|due)=(\d+))#", $params[1], $opts)) {
                    for($i=0;$i<count($opts[2]);$i++) {
                        if($opts[2][$i] == 'warn' or $opts[2][$i] == 'due') {
                            $expiries[$opts[2][$i]] = $opts[3][$i];
                        }
                    }
                }
            }
        }

        // set global expiries if not given yet
        if(!empty($expiries)) {
            $expiries['warn'] = 5;
            $expiries['due']  = 2;
        }

        $match    = preg_replace('#<gtd.*?>|</gtd>#', '', $match);
        $todolist = $this->_todo2array($match, $expiries);

        return ($todolist);
    }

    /**
     * Creates the output
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function render($mode, &$renderer, $data) {
        if($mode == 'xhtml'){
            $renderer->info['cache'] = false;
            $renderer->doc .= $this->_todolist_xhtml($data);
            return true;
        }
        return false;
    }

    /**
     * Parses the todo list into an associative array
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function _todo2array($data, $expiries) {
        global $conf;
        global $ACT;

        // check if we have a serialized todolist already
        if($ACT != 'save' and $ACT != 'preview' and $ACT != 'edit') {
            $fn = $this->_todoFN(md5($data));
            if(file_exists($fn)) {
                return unserialize(io_readFile($fn, false));
            }
        }

        $todos_bydate = array();
        $todos_nodate = array();
        $todolist = array();

        $lines = explode("\n\n",trim($data));

        foreach($lines as $line) {

            // skip empty lines
            if(empty($line)) continue;

            $todo    = array();
            $project = '';
            $context = '';
            $due     = '';
            $desc    = '';

            list($params, $desc) = explode("\n", trim($line));
            $todo['desc'] = trim($desc);

            // check if done
            if($line{0} == '#') {
                $todo['done'] = true;
                $line = substr($line, 1);
            } else {
                $todo['done'] = false;
            }

            // filter context
            if(preg_match("#@(\S+)#", $params, $match)) {
                $todo['context'] = str_replace('_', ' ',$match[1]);
                $params = trim(str_replace($match[0], '', $params));
            } else {
                // no context was given - ignore
                continue;
            }

            // filter project
            if(preg_match("#\bp:(\S+)#", $params, $match)) {
                $todo['project'] = str_replace('_', ' ', $match[1]);
                $params = trim(str_replace($match[0], '', $params));
            }

            // filter warning expiries
            if(preg_match("#\bw:(\d{2})\b#", $params, $match)) {
                $expiries['warn'] = $match[1];
                $params = trim(str_replace($match[0], '', $params));
            }

            // filter date
            if(preg_match("#\bd:(\d{4}-\d{2}-\d{2})\b#", $params, $match)) {
                $todo['date'] = $match[1];
                $todo['priority'] = $this->_get_priority($todo['date'], $expiries);
                $params = trim(str_replace($match[0], '', $params));
            } elseif(preg_match("#\bd:(\d{2}-\d{2})#", $params, $match)) {
                $todo['date'] = date('Y') . '-' . $match[1];
                $todo['priority'] = $this->_get_priority($todo['date'], $expiries);
                $params = trim(str_replace($match[0], '', $params));
            } elseif(preg_match("#\bd:(\d{2})\b#", $params, $match)) {
                $todo['date'] = date('Y') . '-' . date('m') . '-' . $match[1];
                $todo['priority'] = $this->_get_priority($todo['date'], $expiries);
                $params = trim(str_replace($match[0], '', $params));
            }

            if($todo['date']) {
                $todos_bydate[$todo['date']][] = $todo;
            } else {
                array_push($todos_nodate, $todo);
            }
        }

        // do some expensive sorting
        $dates = array_keys($todos_bydate);
        natsort($dates);

        // sort todos by dates first
        foreach($dates as $date) {
            foreach($todos_bydate[$date] as $todo) {
                if(!empty($todo['project'])) {
                    $todolist[$todo['context']]['projects'][$todo['project']][] = array( 'date' => $todo['date'], 
                                                                                         'desc' => $todo['desc'], 
                                                                                         'priority' => $todo['priority'],
                                                                                         'done' => $todo['done'] );
                } else {
                    $todolist[$todo['context']]['todos'][] = array( 'date' => $todo['date'],
                                                                    'desc' => $todo['desc'],
                                                                    'priority' => $todo['priority'],
                                                                    'done' => $todo['done'] );
                }
            }
        }

        // sort todos with no date provided
        foreach($todos_nodate as $todo) {
            if(!empty($todo['project'])) {
                $todolist[$todo['context']]['projects'][$todo['project']][] = array( 'desc' => $todo['desc'], 
                                                                                     'priority' => $todo['priority'],
                                                                                     'done' => $todo['done'] );
            } else {
                $todolist[$todo['context']]['todos'][] = array( 'desc' => $todo['desc'],
                                                                'priority' => $todo['priority'],
                                                                'done' => $todo['done'] );
            }
        }

        // serialize todolist so we don't have to render it each time
        if($ACT == 'save') {
            if(!file_exists($conf['savedir'] . '/cache/gtd/')) {
                mkdir($conf['savedir'] . '/cache/gtd/', $conf['dmode']);
            }
            io_saveFile($this->_todoFN(md5($data)), serialize($todolist));
        }

        // we're done return the list
        return ($todolist);
    }

    /**
     * Generates the XHTML output
     * 
     * @author Michael Klier <chi@chimeric.de>
     */
    function _todolist_xhtml($todolist) {
        $out  = '';

        // create new renderer for the description part
        $renderer = & new Doku_Renderer_xhtml();
        $renderer->smileys  = getSmileys();
        $renderer->entities = getEntities();
        $renderer->acronyms = getAcronyms();
        $renderer->interwiki = getInterwiki();

        foreach($todolist as $context => $todos) {
            $out .= '<div class="plugin_gtd_box">' . DOKU_LF;
            $out .= '<h2 class="plugin_gtd_context">' . htmlspecialchars($context) . '</h2>' . DOKU_LF;
            $out .= '<ul class="plugin_gtd_list">' . DOKU_LF;

            if(!empty($todolist[$context]['projects'])) {
                foreach($todolist[$context]['projects'] as $project => $todos) {
                    $out .= '<li class="plugin_gtd_project"><span class="li plugin_gtd_project">' . htmlspecialchars($project) . '</span>' . DOKU_LF;
                    $out .= '<ul class="plugin_gtd_project">' . DOKU_LF;
                    foreach($todos as $todo) {
                        $out .= @$this->_todo_xhtml(&$renderer, $todo);
                    }
                    $out .= '</ul>' . DOKU_LF;
                    $out .= '</li>' . DOKU_LF;
                }
            }

            if(!empty($todolist[$context]['todos'])) {
                $out .= '<li class="plugin_gtd_project"><span class="li plugin_gtd_project">' . $this->getLang('noproject') . '</span>' . DOKU_LF;
                $out .= '<ul class="plugin_gtd_project">' . DOKU_LF;
                foreach($todolist[$context]['todos'] as $todo) {
                    $out .= @$this->_todo_xhtml(&$renderer, $todo);
                }
                $out .= '</ul>' . DOKU_LF;
                $out .= '</li>' . DOKU_LF;
            }

            $out .= '</ul>' . DOKU_LF;
            $out .= '</div>' . DOKU_LF;
        }

        return ($out);
    }

    /**
     * returns the xhtml for single todo item
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function _todo_xhtml(&$renderer, $todo) {
        $out  = '';

        // reset doc
        $renderer->doc = '';

        $out .= '<li class="plugin_gtd_item ';
        if(isset($todo['date'])) {
            if(!$todo['done']) {
                $out .= 'plugin_gtd_' . $todo['priority'];
            }
        }
        $out .= '"><div class="li">' . DOKU_LF;


        if(isset($todo['date'])) {
            $out .= '<div class="plugin_gtd_date">' . $todo['date'] . '</span>' . DOKU_LF;
        }

        $out .= '<div class="plugin_gtd_desc">'; 

        if($todo['done']) $out .= '<del>';

        // turn description into instructions
        $instructions = p_get_instructions($todo['desc']);

        // loop thru instructions
        foreach($instructions as $instruction) {
            call_user_func_array(array(&$renderer, $instruction[0]),$instruction[1]);
        }

        // strip <p> and </p>
        $desc = $renderer->doc;
        $desc = str_replace("<p>", '', $desc);
        $desc = str_replace("</p>", '', $desc);
        $out .= $desc;

        if($todo['done']) $out .= '</del>';

        $out .= '</div>' . DOKU_LF;
        $out .= '</div></li>' . DOKU_LF;

        return ($out);
    }

    /**
     * Calculates the priority by a given date string
     * and returns the CSS class to use
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function _get_priority($date, $expiries) {

        $ctime = time();
        list($y,$m,$d) = explode('-', $date);
        $etime = mktime(0, 0, 0, $m, $d, $y);

        if(($etime - $ctime) < 0) return 'pass';
        if(($etime - $ctime) <= 60*60*24*$expiries['due']) return 'due';
        if(($etime - $ctime) <= 60*60*24*$expiries['warn']) return 'warn';
        return 'upco';
    }

    /**
     * Returns a file name to store the todolist
     *
     * @author Michael Klier <chi@chimeric.de> 
     */
    function _todoFN($md5) {
        global $ID;
        global $conf;

        $ID = cleanID($ID);
        $ID = str_replace(':', '/', $ID);
        $ID = utf8_encodeFN($ID);
        $fn = $conf['savedir'] . '/cache/gtd/' . $ID . '.' . $md5 . '.gtd';
        return ($fn);
    }
}
