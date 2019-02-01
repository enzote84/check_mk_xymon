<?php

namespace Obsidian\Integration;

use Obsidian\Tools\SimpleTelnet;

class Xymon extends AbstractIntegrator
{
    protected $telnet;
    protected $xymon_host;
    protected $xymon_port;
    const TIMEOUT = 3;
    const DEBUG = 0;
    protected $arrTools;

    function __construct($config)
    {
        parent::__construct($config);
        $this->arrTools = $this->getSourceTools();
        $this->config = $config;
        $this->xymon_host = $config['xymon_host'];
        $this->xymon_port = $config['xymon_port'];
        $this->telnet = new SimpleTelnet($this->xymon_host, $this->xymon_port, self::TIMEOUT, self::DEBUG);
        if (!$this->telnet->isAvailable()) {
            $this->putError("XYMON: Failet to connect to host.");
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
        # TODO: Verify with Natalia.
        if ($date == null) {
            $date = date('Y-m-d H:i:s');
        }
        list($host, $svc) = preg_split('/\+/', $info['ci_monitor']);
        $this->telnet = new SimpleTelnet($this->xymon_host, $this->xymon_port, self::TIMEOUT, self::DEBUG);
        if (($svc == 'check-host-alive') || ($svc == 'DV-check-host-alive')) {
            $out = $this->telnet->execute("GET services\nColumns: state plugin_output\nFilter: description ~~ ^{$host}_info$\n\n");
            $perf = $this->telnet->execute("GET services\nFilter: description ~~ ^{$host}_info$\nStats: sum perf_data\n\n");
        } else {
            $out = $this->telnet->execute("GET services\nColumns: state plugin_output\nFilter: description ~~ ^{$host}_{$svc}$\n\n");
            $perf = $this->telnet->execute("GET services\nFilter: description ~~ ^{$host}_{$svc}$\nStats: sum perf_data\n\n");
        }
        $lines = preg_split("/\r\n|\n|\r/", $out);
        $line = preg_split('/;/', $lines[0], 2);
        $retorno = array(
            'STATE' => intval($line[0]),
            #'OUTPUT' => $line[1],
            'OUTPUT' => $line[1] . " | " . $perf,
            'start_time' => $date,
            'valor' => $perf
        );
        return $retorno;
    }

    public function isEnabled()
    {
        return $this->arrTools['xymon']['enable'];
    }

    public function getTopLevel($filter)
    {
        # All services that represents a host have the pattern: hostname_info
        $out = $this->telnet->execute("GET services\nColumns: description\nFilter: description ~~ ^.*{$filter}.*_info$\n\n");
        # Cut out _info tail
        $out = preg_replace("/_info/", "", $out);
        # Create an array with an element by line
        $array = preg_split("/\r\n|\n|\r/", $out);
        # Create an array to return as result
        $res = array();
        foreach ($array as $r) {
            $res[] = array(
                'parent' => $this->xymon_host,
                'display_name' => $r,
                'host_object_id' => $r
            );
        }
        return $res;
    }

    public function getSecondLevel($parent_id, $filter2)
    {
        # All service are in the form: hostname_service
        $out = $this->telnet->execute("GET services\nColumns: description\nFilter: description ~~ ^{$parent_id}.*\n\n");
        # Cut out hostname_ part
        $out = preg_replace("/{$parent_id}./", "", $out);
        # Create an array with an element by line
        $array = preg_split("/\r\n|\n|\r/", $out);
        # Add a service to monitor hosts availability
        array_unshift($array, "DV-check-host-alive");
        # Create an array to return as result
        $res = array();
        foreach ($array as $value) {
            # Ignore services that do not match filter:
            if (empty($filter2) || preg_match("/{$filter2}/", $value)) {
                $res[] = array(
                    'parent' => $parent_id,
                    'value' => $value,
                    'display_name' => $value,
                    #'service_object_id' => $value
                    'service_object_id' => $parent_id . '+' . $value
                );
            }
        }
        return $res;
    }

    public function getToolId()
    {
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
