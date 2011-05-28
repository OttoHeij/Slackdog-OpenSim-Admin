<?php
/**
 * $Id: opensim.php 9 2009-01-07 00:08:42Z sdague $
 *
 * Author: Sean Dague, sdague@gmail.com
 * Project: OpenSim Plugin for Serverstats, http://forge.opensimulator.org/gf/project/serverstats/
 * License: BSD Revised 
 *
 * Copyright (C) 2008 Sean Dague
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the OpenSim Project nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE DEVELOPERS ``AS IS'' AND ANY EXPRESS OR
 * IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
 * GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER
 * IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR
 * OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
 * ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

class opensim extends source implements source_cached, source_rrd
{
    private $cpuStats;
    private $oldCpuStats;
    
    private $opensim_psfind;
    private $mono_virt;
    private $mono_real;
    private $mono_threads;
    
    public function __construct()
    {
        $this->opensim_psfind = "ps auxw | grep OpenSim.exe | grep -v grep";
    }
	
    public function refreshData()
    {
        $this->getStats();
    }

    public function getStats()
    {
        $this->cpuStats = array();
        $return = 0; 
        $out = "";

        # first we need to figure out the opensim process id
        exec($this->opensim_psfind, $out, $return);
        if ($return !== 0)
        {
            throw new Exception('Could not execute "' . $this->opensim_psfind . '"');
        }
        $outarray = preg_split("/ +/", $out[0]);
        $pid = $outarray[1];

        # get the process stats out of it
        $file = "/proc/$pid/stat";
        $fh = fopen($file, 'r');
        $data = fgets($fh);
        fclose($fh);

        $fields = preg_split("/ +/", $data);
        $this->mono_threads = $fields[19];
        $this->mono_virt = $fields[22];
        $this->mono_real = $fields[23] * 4000;
        $this->cpuStats[1] = $fields[13];
        $this->cpuStats[2] = $fields[14];

        # get the total jiffies time
        $file = "/proc/timer_list";
        if (file_exists($file)) {
            $fh = fopen($file, 'r');
            while (! preg_match('/jiffies:/', $data)) {
                $data = fgets($fh);
            }
            $fields = preg_split("/ +/", $data);
            $this->cpuStats[0] = $fields[1];
        } else { # for pre timer environments
            $this->cpuOldStats[0] = 0;
            $this->cpuStats[0] = 6000;
        }
    }
    
    public function initRRD(rrd $rrd)
    {
        $rrd->addDatasource('opensim_threads', 'GAUGE', null, 0);
        $rrd->addDatasource('opensim_virt', 'GAUGE', null, 0);
        $rrd->addDatasource('opensim_real', 'GAUGE', null, 0);
        $rrd->addDatasource('opensim_cpu_user', 'GAUGE', null, 0);
        $rrd->addDatasource('opensim_cpu_sys', 'GAUGE', null, 0);
    }
	
    public function fetchValues()
    {
        $values = array();
        $values['opensim_threads'] = $this->mono_threads;
        $values['opensim_virt'] = $this->mono_virt;
        $values['opensim_real'] = $this->mono_real;
        if ($this->oldCpuStats[0] < 1) {
            $values['opensim_cpu_user'] = 0;
            $values['opensim_cpu_sys'] = 0;
        } elseif ($this->oldCpuStats[0] > $this->cpuStats[0]) {
            # rollover, so make it zero for 1 slice
            $values['opensim_cpu_user'] = 0;
            $values['opensim_cpu_sys'] = 0;
        } else {
            $delta = $this->cpuStats[0] - $this->oldCpuStats[0];
            $values['opensim_cpu_user'] = (($this->cpuStats[1] - $this->oldCpuStats[1]) / $delta) * 100;
            $values['opensim_cpu_sys'] = (($this->cpuStats[2] - $this->oldCpuStats[2])  / $delta) * 100;
        }
        return $values;
    }

    public function initCache()
    {
        $this->getStats();
        $this->oldCpuStats = $this->cpuStats;
    }
    
    public function loadCache($cachedata)
    {
        $this->oldCpuStats = $cachedata['opensimStats'];
    }
    
    public function getCache()
    {
        return array(
                     'opensimStats' => $this->cpuStats,
                     );
    }
    
}

?>
