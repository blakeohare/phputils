<?php

    class HtmlPlusPlusException extends Exception {

        public function __construct($message, $original_string, $index) {
            $new_msg = array($message, "\n", "The problem occurred somewhere around: '");
            $start = max(0, $index - 30);
            $end = min($index + 30, strlen($original_string));
            for ($i = $start; $i < $end; ++$i) {
                $c = $original_string[$i];
                if ((ord($c) & 0x80) > 0) $c = '?';
                array_push($new_msg, $c);
            }
            array_push($new_msg, "'");
            $message = implode('', $new_msg);
            parent::__construct($message, 0, null);
        }
    }

    /*
        A simple server-side way to create content with complicated but automatic features such as table-of-contents, syntax highlighting in code snippets, and
        other.

        Focus is on technical blog content from for HTML code written from **TRUSTED** 1st-party sources.
        This will NOT sanitize untrusted HTML submitted by your users.

        Basically:
        - Appropriate usage: blog post content
        - Inappropriate usage: the comments


        Features:

            <code>
            Usage: <code language="python" classes="MyClass">...</code>
            Adds syntax highlighting to the snippet of code. If it's on a line by itself, then it creates a large code block.

            <bookmark>
            Usage: <bookmark name="conclusion">Any text or even <heading> tags</heading></bookmar>
            Creates an anchor tag at this point in the page. See also: <tableofcontents>

            <tableofcontents>
            Usage: <tableofcontents/>
            Emits a list of all the <bookmark> tags with links to them. Can only be used once.

            <run>
            Usage: <run>Anything. Can also include newlines</run>
            Does nothing. However, if you are creating a table using the easytable syntax, this makes complicated content possible and also escaping | characters.

            <easytable wide="true">
            Usage:
                <easytable>
                | Column name 1 | Column name 2 |
                ------
                |Row 1 value A|Row 1 value B|
                |   Row 2 Value A   | <run>Row 2
                     value B</run>|
                </easytable>
            Similar to markdown. Creates a pretty table with rows that alternate color and headers. If wide is set to true, then it'll take up the whole width.

            <comment>
            Usage: <comment>anything you don't want rendered to the page, including other HTML</comment>
            A better syntax than <!-- ... -->

            <note>
            <warning>
            Usage: <note>Some sort of text</note>
            Creates a note box. <warning> does similar.

            <image>
            Usage: <image alt="alt text" mouseover="Mouseover text for witty comment.">URL</image>
            Inserts an image pointing at the given URL. If the alt text is not provided, it'll automatically get injected using the filename of the URL.

            <heading>
            Usage: <heading>In conclusion...</heading>
            Just an alias for h2.

            <math>
            Usage: <math>x<sup>2</sup></math>
            Generates a text block that is formatted like the code block, but isn't monospace and allows other inline HTML.
    */
    class HtmlPlusPlusParser {

        private $str;
        private $index = 0;
        private $length;
        private $comment_nest_level = 0;
        private $stack = array();
        private $active_text_listeners = array();
        private $bookmark_counter = 1;
        private $bookmark_list = array();
        private $table_of_contents_index = null;
        private $output = array();
        private $backticks_for_inline_code_enabled = false;

        private $valid_tag_chars = array();

        function __construct($code) {
            $this->str = $code;
            $this->length = strlen($code);

            $letters = 'abcdefghijklmnopqrstuvwxyz';
            $letters .= strtoupper($letters) . '0123456789.-_';
            for ($i = 0; $i < strlen($letters); ++$i) {
                $this->valid_tag_chars[$letters[$i]] = true;
            }
        }

        private function makeError($msg) {
            return new HtmlPlusPlusException($msg, $this->str, $this->index);
        }

        private function output($s) {
            if ($this->comment_nest_level === 0) {
                array_push($this->output, $s);
            }
        }

        private function output_text($s) {
            $this->output($s);
            foreach ($this->active_text_listeners as $k => $_) {
                array_push($this->active_text_listeners[$k], $s);
            }
        }

        private function push_tag($tag) {

            if ($tag['@name'] === 'header') {
                $tag['@name'] = 'h2';
                if (isset($tag['class'])) {
                    $tag['class'] .= ' hpp_header';
                } else {
                    $tag['class'] = 'hpp_header';
                }
            }

            switch ($tag['@name']) {
                case 'comment':
                    $this->comment_nest_level++;
                    break;

                case 'bookmark':
                    $a_name = isset($tag['name']) ? trim($tag['name']) : ('h' . ($this->bookmark_counter++));
                    $uid = base64_encode(random_bytes(30));
                    array_push($this->bookmark_list, array(
                        'name' => $a_name,
                        'uid' => $uid,
                        'label' => '', // replaced by active text listener's content upon </bookmark>
                    ));
                    $tag['@uid'] = $uid;
                    $this->active_text_listeners[$uid] = array();
                    $this->output('<a name="' . htmlspecialchars($a_name) . '"></a>');
                    break;

                case 'tableofcontents':
                    if ($this->comment_nest_level === 0) {
                        if ($this->table_of_contents_index === null) {
                            $content_so_far = implode('', $this->output);
                            $this->output = array($content_so_far);
                            $this->table_of_contents_index = strlen($content_so_far);
                        } else {
                            throw $this->makeError("Cannot have <tableofcontents> tag multiple times.");
                        }
                    }
                    break;

                case 'enablebackticks':
                case 'disablebackticks':
                    break;

                case 'note':
                case 'warning':
                    $this->output('<div class="' . $tag['@name'] . '_box">');
                    break;

                case 'image':
                    $tag['@url'] = '';
                    break;

                case 'code':
                    $tag['@code'] = '';
                    break;

                default:
                    $this->output('<' . $tag['@name']);
                    foreach ($tag as $attr => $value) {
                        if ($attr[0] !== '@' && $attr !== 'comment') {
                            $this->output(' ' . $attr . '="' . $value . '"');
                        }
                    }
                    $this->output('>');
                    break;
            }

            array_push($this->stack, $tag);
        }

        private function pop_tag($tag) {
            // TODO: bug here: you can pop an h2 with a header tag and vice versa
            if ($tag === 'header') $tag = 'h2';

            $opener = null;
            if (count($this->stack) > 0) {
                $opener = array_pop($this->stack);
            }
            if ($opener === null || $opener['@name'] !== $tag) throw $this->makeError("</" . $tag . "> occurred without a corresponding open tag.");

            switch ($tag) {
                case 'comment':
                    $this->comment_nest_level--;
                    break;

                case 'enablebackticks':
                case 'disablebackticks':
                    $this->backticks_for_inline_code_enabled = $opener['@name'] === 'enablebackticks';
                    break;

                case 'bookmark':
                    $uid = $opener['@uid'];
                    $label = implode('', $this->active_text_listeners[$uid]);
                    unset($this->active_text_listeners[$uid]);
                    for ($i = count($this->bookmark_list) - 1; $i >= 0; --$i) {
                        if ($this->bookmark_list[$i]['uid'] === $uid) {
                            $this->bookmark_list[$i]['label'] = $label;
                        }
                    }
                    break;

                case 'image':
                    if (isset($opener['@url'])) {
                        $url = trim($opener['@url']);
                        $alt_text = trim($opener['alt']);
                        if ($alt_text === '') {
                            $t = explode('/', $url);
                            $alt_text = htmlspecialchars($t[count($t) - 1]);
                        }

                        if (isset($opener['mouseover'])) {
                            $opener['title'] = $opener['mouseover'];
                            unset($opener['mouseover']);
                        }

                        $this->output('<img src="' . $url . '" alt="' . $alt_text . '"');
                        foreach ($opener as $attr => $value) {
                            if ($attr[0] !== '@' && $attr !== 'comment') {
                                $this->output(' ' . $attr . '="' . $value . '"');
                            }
                        }
                        $this->output('/>');
                    }
                    break;

                case 'note':
                case 'warning':
                    $this->output('</div>');
                    break;

                case 'code':
                    $syntax = isset($opener['language']) ?  $opener['language'] : 'none';
                    $classes = isset($opener['classes']) ? explode(',', str_replace(' ', ',', $opener['classes'])) : array(); // classes are trimmed and ignored if empty.
                    $code  = $opener['@code'];
                    $html = (new BlakesHtmlSyntaxHighlighter($syntax))->highlight($code, $classes);

                    $this->output('<div');
                    foreach ($opener as $attr => $value) {
                        if ($attr[0] !== '@' && $attr !== 'language' && $attr !== 'classes') {
                            $this->output(' ' . $attr . '="' . $value . '"');
                        }
                    }
                    $this->output('>');

                    $this->output($html);
                    $this->output('</div>');

                    break;

                case 'tableofcontents':
                    break;

                default:
                    $this->output('</' . $tag . '>');
                    break;
            }
        }

        function set_attribute_on_top_tag($name, $value) {
            if (count($this->stack) === 0) throw $this->makeError("No top tag");
            $this->stack[count($this->stack) - 1][$name] = $value;
        }

        function parse() {
            $mode = 'NORMAL'; // { NORMAL | CODE | IMAGE | INLINECODE }
            $stack = array();
            $mode_stack = array('NORMAL');

            while ($this->index < $this->length) {
                $token = $this->pop_token($mode);
                switch ($token['type']) {
                    case 'NL':
                        $this->output("\n");
                        break;

                    case 'TEXT':
                        if ($mode === 'CODE') {
                            $this->set_attribute_on_top_tag('@code', $token['value']);
                        } else if ($mode === 'IMAGE') {
                            $this->set_attribute_on_top_tag('@url', $token['value']);
                        } else if ($mode === 'INLINECODE') {
                            $this->output('<span class="inline_code">');
                            $this->output_text(htmlspecialchars($token['value']));
                            $this->output('</span>');
                        } else {
                            $this->output_text($token['value']);
                        }
                        break;

                    case 'OPENTAG':
                        $this->push_tag($token['value']);
                        switch ($token['value']['@name']) {
                            case 'code':
                                array_push($mode_stack, 'CODE');
                                break;
                            case 'image':
                                array_push($mode_stack, 'IMAGE');
                                break;
                        }
                        $mode = $mode_stack[count($mode_stack) - 1];
                        break;

                    case 'CLOSETAG':
                        $this->pop_tag($token['value']);

                        switch ($token['value']) {
                            case 'code':
                            case 'image':
                                array_pop($mode_stack);
                                $mode = $mode_stack[count($mode_stack) - 1];
                                break;
                        }

                        break;

                    case 'SELFCLOSETAG':
                        $this->push_tag($token['value']);
                        $this->pop_tag($token['value']['@name']);
                        break;

                    case 'BACKTICK':
                        if (!$this->backticks_for_inline_code_enabled) {
                            $this->output('`');
                        } else if ($mode === 'NORMAL') {
                            array_push($mode_stack, 'INLINECODE');
                            $mode = 'INLINECODE';
                        } else if ($mode === 'INLINECODE') {
                            $mode = 'NORMAL';
                            array_pop($mode_stack);
                        } else {
                            // this shouldn't happen.
                            $this->output('`');
                        }
                        break;

                    case 'PIPE':
                        $this->output('|');
                        break;

                    default:
                        throw $this->makeError("Unknown token type: '" . $token['type'] . "'");

                }
            }

            $output = implode($this->output);
            if ($this->table_of_contents_index !== null && count($this->bookmark_list) > 0) {

                $i = $this->table_of_contents_index;
                $front = substr($output, 0, $i);
                $back = substr($output, $i);
                $output = array($front, "\n", '<div class="hpp_tableofcontents">', "\n");
                foreach ($this->bookmark_list as $bookmark) {
                    array_push($output, '<div><a href="#' . $bookmark['name'] . '">' . $bookmark['label'] . '</a></div>', "\n");
                }
                array_push($output, '</div>', "\n", $back);
                $output = implode($output);
            }
            return $output;
        }

        /*
            A token is:
            - TEXT: a contiguous stretch of text that has no line breaks or tags
            - NL: a new line
            - PIPE: a pipe character '|'. Table construction cares about this.
            - OPENTAG: a tag
            - CLOSETAG: a close tag
            - SELFCLOSETAG: a self-closing tag
            - null if there are no tokens left

            This is returned as an array:
            array(
                'type' => TEXT | NL | PIPE | OPENTAG | CLOSETAG | SELFCLOSETAG
                'value' => a string, nothing, nothing, a lookup array of all the attributes + a tagname attribute, or just a string of the tag name, respectively. SELFCLOSETAG returns the same value as the OPENTAG.
            )

            Note that if the $mode is set to CODE, then it will pop everything as a single TEXT value until it finds a </code> or EOF
        */
        function pop_token($mode) {
            if ($this->index >= $this->length) return null;

            if ($mode === 'CODE') {

                for ($i = $this->index; $i < $this->length; ++$i) {
                    if ($this->str[$i] === '<' && substr($this->str, $i, strlen('</code>')) === '</code>') {
                        if ($i === $this->index) {
                            $this->index += strlen('</code>');
                            return array('type' => "CLOSETAG", 'value' => 'code');
                        }
                        $start = $this->index;
                        $this->index = $i;
                        return array('type' => 'TEXT', 'value' => substr($this->str, $start, $this->index - $start));
                    }
                }
                $start = $this->index;
                $this->index = $this->length;
                return array('type' => 'TEXT', 'value' => substr($this->str, $start));
            }

            $c = $this->str[$this->index];
            if ($c === '`' && $this->backticks_for_inline_code_enabled) {
                $this->index++;
                return array('type' => 'BACKTICK');
            }

            if ($mode === 'INLINECODE') {
                return array('type' => 'TEXT', 'value' => $this->pop_token_text($mode));
            }

            switch ($c) {
                case '|':
                    $this->index++;
                    return array('type' => 'PIPE');
                case "\n":
                    $this->index++;
                    return array('type' => 'NL');
                case '<':
                    if ($this->index + 1 < $this->length) {
                        $c2 = strtolower($this->str[$this->index + 1]);
                        if ($c2 === '!') throw $this->makeError("HTML++ does not support <!-- comments. Just use <comment>...</comment> or <tag comment=\"...\"> instead.");
                        if ($c2 === '/') return array('type' => 'CLOSETAG', 'value' => $this->pop_token_close_tag());
                        if (ord($c2) >= ord('a') && ord($c2) <= ord('z')) {
                            $tag = $this->pop_token_open_tag();
                            $type = 'OPENTAG';
                            if ($tag['@isclose']) {
                                unset($tag['@isclose']);
                                $type = 'SELFCLOSETAG';
                            }
                            return array('type' => $type, 'value' => $tag);
                        }
                    }
                    $this->index++;
                    return array('type' => 'TEXT', 'value' => '&lt;'); // it's a loose < character that will get converted into &lt;

                default:
                    return array('type' => 'TEXT', 'value' => $this->pop_token_text($mode));
            }
        }

        function skip_whitespace() {
            while ($this->index < $this->length) {
                switch ($this->str[$this->index]) {
                    case ' ':
                    case "\r":
                    case "\n":
                    case "\t":
                        $this->index++;
                        break;
                    default:
                        return;
                }
            }
        }

        function pop_if_present($value) {
            if ($value === '') throw $this->makeError("Invalid argument: empty string");
            if ($this->index >= $this->length) return false;
            if ($this->str[$this->index] !== $value[0]) return false;
            $len = strlen($value);
            if (substr($this->str, $this->index, $len) === $value) {
                $this->index += $len;
                return true;
            }
            return false;
        }

        function pop_expected($value) {
            if (!$this->pop_if_present($value)) {
                // TODO: write a function to convert index into line and col or print out the previous and following 20 characters or something.
                throw $this->makeError("Expected: '" . $value . "'");
            }
        }

        function pop_simple_word() {
            $output = array();
            while ($this->index < $this->length) {
                $c = $this->str[$this->index];
                if ($this->valid_tag_chars[$c]) {
                    array_push($output, $c);
                    $this->index++;
                } else {
                    break;
                }
            }
            if (count($output) === 0) return null;
            return implode($output);
        }

        function pop_token_open_tag() {
            $output = array();
            $this->pop_expected('<');
            $tag_name = $this->pop_simple_word();
            if ($tag_name === null) throw $this->makeError("Expected tag name");
            $output = array('@name' => $tag_name);
            while (true) {
                $this->skip_whitespace();
                if ($this->pop_if_present('/')) {
                    $this->pop_expected('>');
                    $output['@isclose'] = true;
                    return $output;
                }

                if ($this->pop_if_present('>')) {
                    return $output;
                }

                $attribute_name = $this->pop_simple_word();
                if ($attribute_name === null) throw $this->makeError("Expected attribute name.");
                $this->skip_whitespace();
                $this->pop_expected('=');
                $this->skip_whitespace();
                $escaped_value = $this->pop_attribute_value();

                $output[$attribute_name] = $escaped_value;
            }
        }

        function pop_attribute_value() {
            $start = $this->index;
            if ($this->pop_if_present('"')) {
                $end = $this->length;
                for ($i = $this->index; $i < $this->length; ++$i) {
                    if ($this->str[$i] === '"') {
                        $this->index = $i + 1;
                        return substr($this->str, $start + 1, $this->index - $start - 2);
                    }
                }
                throw $this->makeError("Unclosed string");
            } else {
                return $this->pop_simple_word();
            }
        }

        function pop_token_close_tag() {
            $this->pop_expected('<');
            $this->pop_expected('/');
            $tag_name = $this->pop_simple_word();
            if ($tag_name === null) throw $this->makeError("Expected a tag name.");
            $this->skip_whitespace(); // I think this is technically valid.
            $this->pop_expected('>');
            return $tag_name;
        }

        function pop_token_text($mode) {
            $start = $this->index;
            for ($i = $start; $i < $this->length; ++$i) {
                $c = $this->str[$i];
                switch ($c) {
                    case "\n":
                    case "|":
                    case "<":
                        if ($mode === 'INLINECODE') {
                            if ($c === "\n") throw $this->makeError("Cannot have newline in inline code");
                            break;
                        }
                        $this->index = $i;
                        return substr($this->str, $start, $this->index - $start);
                    case "`":
                        if ($this->backticks_for_inline_code_enabled) {
                            if ($i + 1 < $this->length && $this->str[$i + 1] === '`') { // double backticks to escape
                                $i++;
                            } else {
                                $this->index = $i;
                                return str_replace('``', '`', substr($this->str, $start, $this->index - $start));
                            }
                        }
                        break;
                }
            }
            $this->index = $this->length;
            return substr($this->str, $start);
        }
    }
?>