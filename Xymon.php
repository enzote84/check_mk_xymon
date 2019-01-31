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
        error_log("XYMON: Constructor");
        parent::__construct($config);
        $this->arrTools = $this->getSourceTools();
        $this->config = $config;
        $this->xymon_host = $config['xymon_host'];
        $this->xymon_port = $config['xymon_port'];
        $this->telnet = new SimpleTelnet($this->xymon_host, $this->xymon_port, self::TIMEOUT, self::DEBUG);
        if (!$this->telnet->isAvailable()) {
            error_log("XYMON: Failet to connect to host.");
            $this->error = sprintf("Failed to connect to %s:%d", $this->getHost(), $config['xymon_port']);
            $this->available = false;
        }
        if (isset($_REQUEST['id'])) {
            $this->rootId=$_REQUEST['id'];
            error_log("XYMON: rootID: {$this->rootID}");
        }
        error_log("XYMON: Host: {$this->xymon_host}");
        error_log("XYMON: Port: {$this->xymon_port}");
    }

    public function getParsedValue($source, $info, $monitor_type, $date = null)
    {
        # TODO: Correct errors.
        error_log("XYMON: Getting parsed value");
        error_log("XYMON: Parameteres: Source: {$source} | Info: {$info} | Monitor type: {$monitor_type} | Date: {$date}");
        if ($date == null) {
            $date = date('Y-m-d H:i:s');
        }
        list($host, $svc) = preg_split('/\+/', $info['ci_monitor']);
        error_log("XYMON: Split: Host: {$host} | SVC: {$svc}");
        // echo "======> New: $host ($svc) vs Old localhost (".$info['ci_monitor'].")\n";
        $this->telnet = new SimpleTelnet($this->xymon_host, $this->xymon_port, self::TIMEOUT, self::DEBUG);
        #error_log("XYMON: Telnet: {$this->telnet}");
        if (($svc == 'check-host-alive') || ($svc == 'DV-check-host-alive')) {
            #$out = $this->telnet->execute("GET hosts\nFilter: host_name = $host\nColumns: state plugin_output\n\n");
            $out = $this->telnet->execute("GET services\nColumns: state plugin_output\nFilter: description ~~ ^{$host}_info$\n\n");
        } else {
            #$out = $this->telnet->execute("GET services\nFilter: host_name = $host\nFilter: display_name = $svc\nColumns:state plugin_output\n\n");
            $out = $this->telnet->execute("GET services\nColumns: state plugin_output\nFilter: description ~~ ^{$host}_{$svc}$\n\n");
        }
        error_log("XYMON: OUT: {$out}");
        $lines = preg_split("/\r\n|\n|\r/", $out);
        error_log("XYMON: LINES: {$lines}");
        $line = preg_split('/;/', $lines[0], 2);
        error_log("XYMON: LINE: {$line}");
        $retorno = array(
            'STATE' => intval($line[0]),
            'OUTPUT' => $line[1],
            'start_time' => $date,
            'value' => $line[1]
        );
        error_log("XYMON: RETORNO: {$retorno}");
        return $retorno;
    }

    public function isEnabled()
    {
        error_log("XYMON: isEnabled: {$this->arrTools['xymon']['enable']}");
        return $this->arrTools['xymon']['enable'];
    }

    public function getTopLevel($filter)
    {
        error_log("XYMON: getTopLevel | Filter: {$filter}");
        # All services that represents a host have the pattern: hostname_info
        $out = $this->telnet->execute("GET services\nColumns: description\nFilter: description ~~ ^.*{$filter}.*_info$\n\n");
        error_log("XYMON: OUT: {$out}");
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
                #'host_object_id' => $this->xymon_host . '+' . $r
            );
            error_log("XYMON: Host: {$r}");
        }
        return $res;
    }

    public function getSecondLevel($parent_id, $filter2)
    {
        error_log("XYMON: getSecondLevel | PartentID: {$parent_id} | Filter: {$filter2}");
        # Cut out xymon server part.
        #$parent_id = preg_replace("/{$this->xymon_host}./", "", $parent_id);
        # All service are in the form: hostname_service
        $out = $this->telnet->execute("GET services\nColumns: description\nFilter: description ~~ ^{$parent_id}.*\n\n");
        error_log("XYMON: OUT: {$out}");
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
                error_log("XYMON: Service: {$value} | Parent: {$parent_id}");
            }
        }
        return $res;
    }

    public function getToolId()
    {
        error_log("XYMON: getToolId: 2003");
        return 2003;
    }

    public function getToolKeyname()
    {
        error_log("XYMON: getToolKeyname: xymon");
        return 'xymon';
    }

    public function getToolText()
    {
        error_log("XYMON: getToolText: Xymon");
        return "Xymon";
    }

    /**
     * @return mixed
     */
    public function getHost()
    {
        error_log("XYMON: getHost: {$this->xymon_host}");
        return $this->xymon_host;
    }

}
