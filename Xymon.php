<?php

namespace Obsidian\Integration;

use Obsidian\Tools\SimpleTelnet;

class Xymon extends AbstractIntegrator
{
    protected $telnet;
    protected $xymon_host;
    protected $xymon_port;
    const TIMEOUT = 5;
    const DEBUG = 0;
    const EOL_PATTERN = "/\r\n|\n|\r/";
    protected $arrTools;

    function __construct($config)
    {
        parent::__construct($config);
        # Configure attributes:
        $this->arrTools = $this->getSourceTools();
        $this->config = $config;
        $this->xymon_host = $config['xymon_host'];
        $this->xymon_port = $config['xymon_port'];
        # Connect to Xymon server:
        $this->telnet = new SimpleTelnet($this->xymon_host, $this->xymon_port, self::TIMEOUT, self::DEBUG);
        if (!$this->telnet->isAvailable()) {
            $this->putError("XYMON: Failet to connect to host: {$this->xymon_host}:{$this->xymon_port}");
            $this->error = sprintf("Failed to connect to %s:%d", $this->getHost(), $config['xymon_port']);
            $this->available = false;
        }
        if (isset($_REQUEST['id'])) {
            $this->rootId=$_REQUEST['id'];
            $this->putError("XYMON: rootID: {$this->rootID}");
        }
    }

    public function getParsedValue($source, $info, $monitor_type, $date = null)
    {
        # Get a service status from Xymon and parse it to show on obsidian
        if ($date == null) {
            $date = date('Y-m-d H:i:s');
        }
        # Get host and service name:
        list($host, $svc) = preg_split('/\+/', $info['ci_monitor']);
        # Connect to Xymon server:
        $this->telnet = new SimpleTelnet($this->xymon_host, $this->xymon_port, self::TIMEOUT, self::DEBUG);
        # Check if it is a host alive service or other:
        if (($svc == 'check-host-alive') || ($svc == 'DV-check-host-alive')) {
            $out = $this->telnet->execute("option=data&host={$host}&service=info");
        }
        else {
            $out = $this->telnet->execute("option=data&host={$host}&service={$svc}");
        }
        # It should be just one line, but it is better to split in elements:
        $lines = preg_split(self::EOL_PATTERN, $out);
        # Fields in the line are separated by "##"
        # The spected fields are:
        #    service state (0, 1, 2, 3) | service status description | performance data
        $line = preg_split('/##/', $lines[0]);
        # Return array:
        return array(
            'STATE' => intval($line[0]),
            'OUTPUT' => $line[1] . " | " . $line[2],
            'start_time' => $date,
            'valor' => $line[2]
        );
    }

    public function isEnabled()
    {
        return $this->arrTools['xymon']['enable'];
    }

    public function getTopLevel($filter)
    {
        # Get a list of all host from Xymon:
        $out = $this->telnet->execute("option=hosts");
        # Create an array with an element by line
        $array = preg_split(self::EOL_PATTERN, $out);
        # Create an array to return as result
        $res = array();
        foreach ($array as $r) {
            # Ignore any empty lines and hosts that do not match filter:
            if (!empty($r) && (empty($filter) || preg_match("/{$filter}/", $r))) {
                $res[] = array(
                    'display_name' => $r,
                    'host_object_id' => $r
                );
            }
        }
        return $res;
    }

    public function getSecondLevel($parent_id, $filter2)
    {
        # Get all services from a particular host:
        $out = $this->telnet->execute("option=services&host={$parent_id}");
        # Create an array with an element by line
        $array = preg_split(self::EOL_PATTERN, $out);
        # Add a service to monitor hosts availability
        array_unshift($array, "DV-check-host-alive");
        # Create an array to return as result
        $res = array();
        foreach ($array as $value) {
            # Ignore any empty line and services that do not match filter:
            if (!empty($value) && (empty($filter2) || preg_match("/{$filter2}/", $value))) {
                $res[] = array(
                    'parent' => $parent_id,
                    'value' => $value,
                    'display_name' => $value,
                    'service_object_id' => $parent_id . '+' . $value
                );
            }
        }
        return $res;
    }

    public function getToolId()
    {
        # The toolid that it is assigned when first upload the class.
        # A query can be made by selecting id from bsm_sourcetool and the source is xymon
        return 2003;
    }

    public function getToolKeyname()
    {
        return 'xymon';
    }

    public function getToolText()
    {
        return "Xymon";
    }

    /**
     * @return mixed
     */
    public function getHost()
    {
        return $this->xymon_host;
    }

}
