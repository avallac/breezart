<?php

class Breezart
{
    protected int $cntRequest = 0;
    protected string $url;
    protected float $lastQueryTs = 0;

    const REG_ADDRESS_SPEED = 0;
    const REG_ADDRESS_TEMP = 1;
    const REG_ADDRESS_STATE = 3;

    const READ_IR = 4;
    const WRITE_SHR = 6;

    public function __construct($url)
    {
        $this->url = $url;
    }

    /**
     * @param $SesID
     * @param array $bData
     * @return array
     * @throws Exception
     */
    protected function makeRequest($SesID, array $bData) : array
    {
        if ($this->lastQueryTs + 1 > microtime(true)) {
            usleep(($this->lastQueryTs + 1 - microtime(true)) * 1000);
        }
        $data = '';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        foreach ($bData as $item) {
            $data .= chr($item);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $data = curl_exec($ch);
        $this->lastQueryTs = microtime(true);

        $result = [];
        for($i = 0; isset($data[$i]); $i++) {
            $result[$i] = ord($data[$i]);
        }

        curl_close($ch);

        if ($result) {
            $RespSesID = ($result[0] << 8) | $result[1];
            if ($RespSesID != $SesID) {
                throw new Exception('bad session');
            } else {
                if ($result[7] > 0x80) {
                    throw new Exception("Modbus Error #" . $result[8]);
                }

                return $result;
            }
        }

        throw new Exception('unknown error');
    }

    protected function convUnSignToSign(int $UnSignVar) : int
    {
        $HWord = 32767;
        if ($UnSignVar > $HWord) {
            $SignVar = $UnSignVar - $HWord;
        } else {
            $SignVar = $UnSignVar;
        }
        return $SignVar;
    }

    /**
     * @param string $address
     * @param int $reg
     * @return array
     * @throws Exception
     */
    protected function readReg(string $address, int $reg) : array
    {
        $this->cntRequest++;

        $SesID = ($this->cntRequest + 0x10000) % 0x10000;
        $bData = [
            ($SesID>>8)&0xFF,
            $SesID&0xFF,
            0,
            0,
            0,
            6,
            1,
            self::READ_IR,
            ($address>>8)&0xFF,
            $address&0xFF,
            0,
            $reg
        ];

        $answer = $this->makeRequest($SesID, $bData);

        $return = [];
        $params = 0;
        for ($i=0; $i < $answer[8]/2; $i++) {
            $return[$i] = ($answer[$i*2 + 9]<<8) | $answer[$i*2 + 10];
            $params++;
        }

        if ($params === 0) {
            throw new Exception('Error');
        }

        return $return;
    }

    /**
     * @param $address
     * @param $input
     * @throws Exception
     */
    protected function writeReg($address, $input) : void
    {
        $this->cntRequest++;

        $SesID = ($this->cntRequest + 0x10000) % 0x10000;
        $bData = [
            ($SesID>>8)&0xFF,
            $SesID&0xFF,
            0,
            0,
            0,
            6,
            1,
            self::WRITE_SHR,
            ($address>>8)&0xFF,
            $address&0xFF,
            ($input[0]>>8)&0xFF,
            $input[0]&0xFF
        ];

        $this->makeRequest($SesID, $bData);
    }

    /**
     * @return array
     * @throws Exception
     */
    public function getBase() : array
    {
        $reg = $this->readReg(10, 13);
        return [
            'state' => ($reg[1] + $reg[2]*0x10000) & 3,
            'temp' => $this->convUnSignToSign($reg[3])/10,
            'speed' => $reg[7] < 10 ? 0 : round(($reg[7]-1)/1000, 1)
        ];
    }

    /**
     * @return array
     * @throws Exception
     */
    public function getExtend() : array
    {
        $reg = $this->readReg(64000, 12);

        return [
            'state' => ($reg[1] + $reg[2]*0x10000) & 3,
            'filter' => round($reg[6]/100),
            'tenPwr' => $reg[11]*10
        ];
    }

    /**
     * @param int $temp
     * @throws Exception
     */
    public function setTemp(int $temp) : void
    {
        $this->writeReg(self::REG_ADDRESS_TEMP, [$temp * 10]);
    }

    /**
     * @param int $speed
     * @throws Exception
     */
    public function setSpeed(int $speed) : void
    {
        $this->writeReg(self::REG_ADDRESS_SPEED, [$speed * 1000]);
    }

    /**
     * @throws Exception
     */
    public function on() : void
    {
        $this->writeReg(self::REG_ADDRESS_STATE, [1]);
    }

    /**
     * @throws Exception
     */
    public function off() : void
    {
        $this->writeReg(self::REG_ADDRESS_STATE, [0]);
    }
}