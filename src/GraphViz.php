<?php

namespace Graphp\GraphViz;

use Graphp\Graph\Edge;
use Graphp\Graph\EdgeDirected;
use Graphp\Graph\Entity;
use Graphp\Graph\Exception\UnexpectedValueException;
use Graphp\Graph\Graph;
use Graphp\Graph\Vertex;

class GraphViz
{
    /**
     * file output format to use
     *
     * @var string
     * @see GraphViz::setFormat()
     */
    private $format = 'png';

    /**
     * Either the name of full path to GraphViz layout.
     *
     * @var string
     * @see GraphViz::setExecutable()
     */
    private $executable = 'dot';

    /**
     * string to use as indentation for dot output
     *
     * @var string
     * @see GraphViz::createScript()
     */
    private $formatIndent = '  ';

    private $attributeFlow = 'flow';
    private $attributeCapacity = 'capacity';
    private $attributeWeight = 'weight';

    private $attributeGroup = 'group';
    private $attributeBalance = 'balance';

    const DELAY_OPEN = 2.0;

    const EOL = PHP_EOL;

    public function __construct()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->executable = 'dot.exe';
        }
    }

    /**
     * Change the executable to use.
     *
     * Usually, your graphviz executables should be located in your $PATH
     * environment variable and invoking a mere `dot` is sufficient. If you
     * have no access to your $PATH variable, use this method to set the path
     * to your graphviz dot executable.
     *
     * This should contain '.exe' on windows.
     * - /full/path/to/bin/dot
     * - neato
     * - dot.exe
     * - c:\path\to\bin\dot.exe
     *
     * @param string $executable
     * @return GraphViz $this (chainable)
     */
    public function setExecutable($executable) {
        $this->executable = $executable;

        return $this;
    }

    /**
     * return executable to use
     *
     * @return string
     * @see GraphViz::setExecutable()
     */
    public function getExecutable() {
        return $this->executable;
    }

    /**
     * set graph image output format
     *
     * @param  string   $format png, svg, ps2, etc. (see 'man dot' for details on parameter '-T')
     * @return GraphViz $this (chainable)
     */
    public function setFormat($format)
    {
        $this->format = $format;

        return $this;
    }

    /**
     * create and display image for this graph
     *
     * @param Graph $graph graph to display
     * @return void
     * @uses GraphViz::createImageFile()
     */
    public function display(Graph $graph)
    {
        // echo "Generate picture ...";
        $tmp = $this->createImageFile($graph);

        static $next = 0;
        if ($next > microtime(true)) {
            // wait some time between calling xdg-open because earlier calls will be ignored otherwise
            //echo '[delay flooding xdg-open]' . PHP_EOL;
            sleep(self::DELAY_OPEN);
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // open image in untitled, temporary background shell
            exec('start "" ' . escapeshellarg($tmp) . ' >NUL');
        } elseif (strtoupper(PHP_OS) === 'DARWIN') {
            // open image in background (redirect stdout to /dev/null, sterr to stdout and run in background)
            exec('open ' . escapeshellarg($tmp) . ' > /dev/null 2>&1 &');
        } else {
            // open image in background (redirect stdout to /dev/null, sterr to stdout and run in background)
            exec('xdg-open ' . escapeshellarg($tmp) . ' > /dev/null 2>&1 &');
        }

        $next = microtime(true) + self::DELAY_OPEN;
        // echo "... done\n";
    }

    /**
     * create image file data contents for this graph
     *
     * @param Graph $graph graph to display
     * @return string
     * @uses GraphViz::createImageFile()
     */
    public function createImageData(Graph $graph)
    {
        $file = $this->createImageFile($graph);
        $data = file_get_contents($file);
        unlink($file);

        return $data;
    }

    /**
     * create base64-encoded image src target data to be used for html images
     *
     * @param Graph $graph graph to display
     * @return string
     * @uses GraphViz::createImageData()
     */
    public function createImageSrc(Graph $graph)
    {
        $format = $this->format;
        if ($this->format === 'svg' || $this->format === 'svgz') {
            $format = 'svg+xml;charset=' . $graph->getAttribute('graphviz.graph.charset', 'UTF-8');
        }

        return 'data:image/' . $format . ';base64,' . base64_encode($this->createImageData($graph));
    }

    /**
     * create image html code for this graph
     *
     * @param Graph $graph graph to display
     * @return string
     * @uses GraphViz::createImageSrc()
     */
    public function createImageHtml(Graph $graph)
    {
        if ($this->format === 'svg' || $this->format === 'svgz') {
            return '<object type="image/svg+xml" data="' . $this->createImageSrc($graph) . '"></object>';
        }

        return '<img src="' . $this->createImageSrc($graph) . '" />';
    }

    /**
     * create image file for this graph
     *
     * @param Graph $graph graph to display
     * @return string                   filename
     * @throws UnexpectedValueException on error
     * @uses GraphViz::createScript()
     */
    public function createImageFile(Graph $graph)
    {
        $script = $this->createScript($graph);
        // var_dump($script);

        $tmp = tempnam(sys_get_temp_dir(), 'graphviz');
        if ($tmp === false) {
            throw new UnexpectedValueException('Unable to get temporary file name for graphviz script');
        }

        $ret = file_put_contents($tmp, $script, LOCK_EX);
        if ($ret === false) {
            throw new UnexpectedValueException('Unable to write graphviz script to temporary file');
        }

        $ret = 0;

        $executable = $this->getExecutable();
        system(escapeshellarg($executable) . ' -T ' . escapeshellarg($this->format) . ' ' . escapeshellarg($tmp) . ' -o ' . escapeshellarg($tmp . '.' . $this->format), $ret);
        if ($ret !== 0) {
            throw new UnexpectedValueException('Unable to invoke "' . $executable .'" to create image file (code ' . $ret . ')');
        }

        unlink($tmp);

        return $tmp . '.' . $this->format;
    }

    /**
     * create graphviz script representing this graph
     *
     * @param Graph $graph graph to display
     * @return string
     * @uses Directed::hasDirected()
     * @uses Graph::getVertices()
     * @uses Graph::getEdges()
     */
    public function createScript(Graph $graph)
    {
        $directed = false;
        foreach ($graph->getEdges() as $edge) {
            if ($edge instanceof EdgeDirected) {
                $directed = true;
                break;
            }
        }

        /*
         * The website [http://www.graphviz.org/content/dot-language] uses the term `ID` when displaying
         * the abstract grammar for the DOT language.
         * But the man pages for dot use the term `name` when describing the graph file language.
         */
        $name = $graph->getAttribute('graphviz.name');
        if ($name !== null) {
            $name = $this->escape($name) . ' ';
        }

        $script = ($directed ? 'di':'') . 'graph ' . $name . '{' . self::EOL;

        // add global attributes
        $globals = array(
            'graph' => 'graphviz.graph.',
            'node'  => 'graphviz.node.',
            'edge'  => 'graphviz.edge.',
        );

        foreach ($globals as $key => $prefix) {
            if ($layout = $this->getAttributesPrefixed($graph, $prefix)) {
                $script .= $this->formatIndent . $key . ' ' . $this->escapeAttributes($layout) . self::EOL;
            }
        }

        // build an array to map vertex hashes to vertex IDs for output
        $tid = 0;
        $vids = array();

        $groups = array();
        foreach ($graph->getVertices() as $vertex) {
            assert($vertex instanceof Vertex);
            $groups[$vertex->getAttribute('group', 0)][] = $vertex;

            $id = $vertex->getAttribute('id');
            if ($id === null) {
                $id = ++$tid;
            }

            $vids[\spl_object_hash($vertex)] = $id;
        }

        // only cluster vertices into groups if there are at least 2 different groups
        if (count($groups) > 1) {
            $indent = str_repeat($this->formatIndent, 2);
            $gid = 0;
            // put each group of vertices in a separate subgraph cluster
            foreach ($groups as $group => $vertices) {
                $script .= $this->formatIndent . 'subgraph cluster_' . $gid++ . ' {' . self::EOL .
                           $indent . 'label = ' . $this->escape($group) . self::EOL;
                foreach ($vertices as $vertex) {
                    $vid = $vids[\spl_object_hash($vertex)];
                    $layout = $this->getLayoutVertex($vertex, $vid);

                    $script .= $indent . $this->escape($vid);
                    if ($layout) {
                        $script .= ' ' . $this->escapeAttributes($layout);
                    }
                    $script .= self::EOL;
                }
                $script .= '  }' . self::EOL;
            }
        } else {
            // explicitly add all isolated vertices (vertices with no edges) and vertices with special layout set
            // other vertices wil be added automatically due to below edge definitions
            foreach ($graph->getVertices() as $vertex){
                $vid = $vids[\spl_object_hash($vertex)];
                $layout = $this->getLayoutVertex($vertex, $vid);

                if ($layout || [] === $vertex->getEdges()) {
                    $script .= $this->formatIndent . $this->escape($vid);
                    if ($layout) {
                        $script .= ' ' . $this->escapeAttributes($layout);
                    }
                    $script .= self::EOL;
                }
            }
        }

        $edgeop = $directed ? ' -> ' : ' -- ';

        // add all edges as directed edges
        foreach ($graph->getEdges() as $currentEdge) {
            $both = $currentEdge->getVertices();
            $currentStartVertex = $both[0];
            $currentTargetVertex = $both[1];

            $script .= $this->formatIndent . $this->escape($vids[\spl_object_hash($currentStartVertex)]) . $edgeop . $this->escape($vids[\spl_object_hash($currentTargetVertex)]);

            $layout = $this->getLayoutEdge($currentEdge);

            // this edge is not a loop and also points to the opposite direction => this is actually an undirected edge
            if ($directed && $currentStartVertex !== $currentTargetVertex && $currentEdge->isConnection($currentTargetVertex, $currentStartVertex)) {
                $layout['dir'] = 'none';
            }
            if ($layout) {
                $script .= ' ' . $this->escapeAttributes($layout);
            }

            $script .= self::EOL;
        }
        $script .= '}' . self::EOL;

        return $script;
    }

    /**
     * escape given string value and wrap in quotes if needed
     *
     * @param  string $id
     * @return string
     * @link http://graphviz.org/content/dot-language
     */
    private function escape($id)
    {
        // see @link: There is no semantic difference between abc_2 and "abc_2"
        // numeric or simple string, no need to quote (only for simplicity)
        if (preg_match('/^(?:\-?(?:\.\d+|\d+(?:\.\d+)?))$/i', $id)) {
            return $id;
        }

        return '"' . str_replace(array('&', '<', '>', '"', "'", '\\', "\n"), array('&amp;', '&lt;', '&gt;', '&quot;', '&apos;', '\\\\', '\\l'), $id) . '"';
    }

    /**
     * get escaped attribute string for given array of (unescaped) attributes
     *
     * @param  array  $attrs
     * @return string
     * @uses GraphViz::escape()
     */
    private function escapeAttributes($attrs)
    {
        $script = '[';
        $first = true;
        foreach ($attrs as $name => $value) {
            if ($first) {
                $first = false;
            } else {
                $script .= ' ';
            }

            if (\substr($name, -5) === '_html') {
                // HTML-like labels need to be wrapped in angle brackets
                $name = \substr($name, 0, -5);
                $value = '<' . $value . '>';
            } elseif (\substr($name, -7) === '_record') {
                // record labels need to be quoted
                $name = \substr($name, 0, -7);
                $value = '"' . \str_replace('"', '\\"', $value) . '"';
            } else {
                // all normal attributes need to be escaped and/or quoted
                $value = $this->escape($value);
            }

            $script .= $name . '=' . $value;
        }
        $script .= ']';

        return $script;
    }

    private function getLayoutVertex(Vertex $vertex, $vid)
    {
        $layout = $this->getAttributesPrefixed($vertex, 'graphviz.');

        $balance = $vertex->getAttribute($this->attributeBalance);
        if ($balance !== NULL) {
            if ($balance > 0) {
                $balance = '+' . $balance;
            }
            if (!isset($layout['label'])) {
                $layout['label'] = $vid;
            }
            $layout['label'] .= ' (' . $balance . ')';
        }

        return $layout;
    }

    protected function getLayoutEdge(Edge $edge)
    {
        $layout = $this->getAttributesPrefixed($edge, 'graphviz.');

        // use flow/capacity/weight as edge label
        $label = NULL;

        $flow = $edge->getAttribute($this->attributeFlow);
        $capacity = $edge->getAttribute($this->attributeCapacity);
        // flow is set
        if ($flow !== NULL) {
            // NULL capacity = infinite capacity
            $label = $flow . '/' . ($capacity === NULL ? '∞' : $capacity);
            // capacity set, but not flow (assume zero flow)
        } elseif ($capacity !== NULL) {
            $label = '0/' . $capacity;
        }

        $weight = $edge->getAttribute($this->attributeWeight);
        // weight is set
        if ($weight !== NULL) {
            if ($label === NULL) {
                $label = $weight;
            } else {
                $label .= '/' . $weight;
            }
        }

        if ($label !== NULL) {
            if (isset($layout['label'])) {
                $layout['label'] .= ' ' . $label;
            } else {
                $layout['label'] = $label;
            }
        }
        return $layout;
    }

    /**
     * @param Graph|Vertex|Edge $entity
     * @param string            $prefix
     * @return array
     */
    private function getAttributesPrefixed(Entity $entity, $prefix)
    {
        $len = \strlen($prefix);
        $attributes = array();
        foreach ($entity->getAttributes() as $name => $value) {
            if (\strpos($name, $prefix) === 0) {
                $attributes[substr($name, $len)] = $value;
            }
        }

        return $attributes;
    }
}
