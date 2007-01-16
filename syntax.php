<?php
/**
 * DokuWiki Syntax Plugin GTD (Getting Things Done)
 * 
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Michael Klier <chi@chimeric.de>
 */
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

if(!defined('DW_LF')) define('DW_LF',"\n");
 
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
            'date'   => '2007-01-16',
            'name'   => 'GTD (Getting Things Done)',
            'desc'   => 'Implements a ToDo List following the principles of GTD.',
            'url'    => 'http://www.chimeric.de/projects/dokuwiki/plugin/gtd',
        );
    }
 
    /**
     * Syntax Type
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     */
    function getType()  { return 'substition'; }
    function getPType() { return 'block'; }
    function getSort()  { return 320; }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) { $this->Lexer->addSpecialPattern('<gtd>.+?</gtd>',$mode,'plugin_gtd'); }
 
    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler) {
        $match = substr($match, 5, -6);
        $todos = $this->_todo2array($match);
        return ($todos);
    }

    /**
     * Create output
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
    function _todo2array($data) {

        $todolist = array();

        $lines = explode("\n",trim($data));

        foreach($lines as $line) {

            // skip empty lines
            if(empty($line)) continue;

            $todo    = array();
            $project = '';
            $context = '';
            $due     = '';
            $desc    = '';

            $line = trim($line);

            // check if done
            if($line{0} == '#') {
                $todo['done'] = true;
                $line = substr($line, 1);
            } else {
                $todo['done'] = false;
            }

            // filter context
            if(preg_match("#@([^ ]+)#", $line, $match)) {
                $context = trim(str_replace('_', ' ',$match[1]));
                $line = trim(str_replace($match[0], '', $line));
            } else {
                // skip further processing if no context was given
                continue;
            }

            // filter date
            if(preg_match("#\d{4}-\d{2}-\d{2}#", $line, $match)) {
                $todo['date'] = $match[0];
                $todo['priority'] = $this->_get_priority($todo['date']);
                $line = trim(str_replace($match[0], '', $line));
            } elseif(preg_match("#\d{2}-\d{2}#", $line, $match)) {
                $todo['date'] = date('Y') . '-' . $match[0];
                $todo['priority'] = $this->_get_priority($todo['date']);
                $line = trim(str_replace($match[0], '', $line));
            }

            // rest of the line must be the description
            // skip further processing if description is empty
            $todo['desc'] = trim($line);
            if(empty($todo['desc'])) continue;

            $todolist[$context][] = $todo;
        }

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

        foreach($todolist as $context => $items) {
            $out .= '<div class="plugin_gtd_box">' . DW_LF;
            $out .= '<h2 class="plugin_gtd_context">' . htmlspecialchars($context) . '</h2>' . DW_LF;
            $out .= '<ul>' . DW_LF;

            foreach($items as $item) {
                $out .= '<li class="plugin_gtd_item"><span class="li">' . DW_LF;

                if($item['done']) $out .= '<del>';

                if(isset($item['date'])) {
                    $out .= '<span class="plugin_gtd_date ';
                    if(!$item['done']) $out .= 'plugin_gtd_' . $item['priority'];
                    $out .= '">' . $item['date'] . '</span>' . DW_LF;
                }

                $out .= '<span class="plugin_gtd_desc">'; 

                // turn description into instructions
                $instructions = p_get_instructions($item['desc']);

                // reset doc
                $renderer->doc = '';

                // loop thru instructions
                foreach($instructions as $instruction) {
                    call_user_func_array(array(&$renderer, $instruction[0]),$instruction[1]);
                }

                // strip <p> and </p>
                $desc = $renderer->doc;
                $desc = str_replace("<p>", '', $desc);
                $desc = str_replace("</p>", '', $desc);
                $out .= $desc;

                $out .= '</span>' . DW_LF;


                if(isset($item['project'])) {
                    $out .= '<span class="plugin_gtd_project">(' . htmlspecialchars($item['project']) . ')</span>';
                }

                if($item['done']) $out .= '</del>';

                $out .= '</span></li>' . DW_LF;
            }

            $out .= '</ul>' . DW_LF;
            $out .= '</div>' . DW_LF;
        }

        return ($out);
    }

    /**
     * calculates the priority by a given date string
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function _get_priority($date) {
        $ctime = time();
        list($y,$m,$d) = explode('-', $date);
        $dtime = mktime(0, 0, 0, $m, $d, $y);
        if($ctime >= $dtime) return 'over';
        if(($dtime - $ctime) >= 60*60*24*7) return 'upcoming';
        return 'current';
    }
}
