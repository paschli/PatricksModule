<?
require_once __DIR__ . '/../libs/TasmotaService.php';
class PIMQTT extends TasmotaService
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent('{EE0D345A-CF31-428A-A613-33CE98E752DD}');
        $this->RegisterPropertyString('Topic', '');
        $this->RegisterPropertyString('FullTopic', '%prefix%/%topic%');
        $ID_Dummy=$this->RegisterVariableFloat('Tasmota_RSSI', 'RSSI');
        $ID_Parent=IPS_GetParent($ID_Dummy);
        IPS_LogMessage("PIMQTT","ID Parent=".$ID_Parent);
        IPS_DeleteVariable($ID_Dummy);
        $ID_Cat_Devices=@$this->GetIDForIdent('DEVICES');
        if($ID_Cat_Devices===FALSE){
            $ID_Cat_Devices=IPS_CreateCategory();
            IPS_SetIdent($ID_Cat_Devices,'DEVICES');
            IPS_SetParent($ID_Cat_Devices, $ID_Parent);
            IPS_SetName($ID_Cat_Devices, 'Devices');
        }
        $this->RegisterPropertyInteger('$ID_Cat_Devices',$ID_Cat_Devices);
        $this->RegisterPropertyInteger('$ID_Instance', $ID_Parent);
       
    }
    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->ConnectParent('{EE0D345A-CF31-428A-A613-33CE98E752DD}');
        $topic = $this->ReadPropertyString('Topic');
        $this->SetReceiveDataFilter('.*' . $topic . '.*');
    }
  
    public function ReceiveData($JSONString)
    {
        define('float', 2);
        define('integer', 1);
        $this->SendDebug('JSON', $JSONString, 0);
        if (!empty($this->ReadPropertyString('Topic'))) {
            $this->SendDebug('ReceiveData JSON', $JSONString, 0);
            $data = json_decode($JSONString);
            // Buffer decodieren und in eine Variable schreiben
            $Buffer = json_decode($data->Buffer);
            $this->SendDebug('Topic', $Buffer->TOPIC, 0);
            $this->SendDebug('MSG', $Buffer->MSG, 0);
            $mode=0;
            
            $Topic = $Buffer->TOPIC;    
            IPS_LogMessage("PIMQTT",'Topic received: '.$Topic);
            
            if(fnmatch('*miflora_sensor*', $Topic)){  
                
                IPS_LogMessage("PIMQTT",'miflora received: '.$Topic);
                $mode=1;
                $this->proceed_miflora($Buffer);
            }
            if(fnmatch('*BLT_Temp_Sensor*', $Topic)){    
                IPS_LogMessage("PIMQTT",'BLT_Temp_Sensor received: '.$Topic);
                $this->SendDebug('Decode Topic', 'BLT_Temp...', 0);
                $mode=2;
            }
            
            if(!$mode){
                IPS_LogMessage("PIMQTT",'unknown topic!');
                $this->SendDebug('Decode Topic', 'unknow topic!', 0);
            }
            
            if($mode==1){
                
//                IPS_LogMessage("PIMQTT",'miflora execute!');
//                IPS_LogMessage("PIMQTT",'Buffer -> MSG  '.strval($Buffer->MSG));
//                if(fnmatch('*$announce*', $Topic)){    
//                    IPS_LogMessage("PIMQTT",'announce received: '.$Topic);
//                    $Message=json_decode($Buffer->MSG,TRUE);
//                    if($Message==''){
//                        IPS_LogMessage("PIMQTT",'Message leer ');
//                        return(0);
//                    }
////                    IPS_LogMessage("PIMQTT",'Name: '.strval($Message[1]));
//                    if (array_key_exists('name_pretty', $Message)) {
//                        IPS_LogMessage("PIMQTT",'Namen gefunden!!! '.$Message['name_pretty']);
//                        $Sensor=$Message['name_pretty'];
//                    }
//                    else{
//                        IPS_LogMessage("PIMQTT",'Name nicht gefunden! ');
//                    }
//                }
//                else {
//                    $position=strpos($Topic,'/')+1;        
//                    $Sensor= substr($Topic,$position);                  
//                    IPS_LogMessage("PIMQTT",'Sensor Name= '.$Sensor);
//                    $Modul_Ident=$Sensor;
//                    $ID_Modul=@IPS_GetObjectIDByIdent($Modul_Ident, $this->ReadPropertyInteger('$ID_Cat_Devices'));
//                    if($ID_Modul===FALSE){
//                        $ID_Modul= IPS_CreateCategory();
//                        IPS_SetName($ID_Modul, $Sensor);
//                        IPS_SetParent($ID_Modul, $this->ReadPropertyInteger('$ID_Cat_Devices'));
//                        IPS_SetIdent($ID_Modul, $Modul_Ident);
//                        IPS_LogMessage("PIMQTT",'Create Cat in'.$this->ReadPropertyInteger('$ID_Cat_Devices'));
//                        IPS_LogMessage("PIMQTT",'Create Cat'.$Sensor);
//                    }
//                    $Message=json_decode($Buffer->MSG,TRUE);
//
//                    $this->check_message($Message,'battery', $ID_Modul,integer);
//                    $this->check_message($Message,'light', $ID_Modul,integer);
//                    $this->check_message($Message,'moisture', $ID_Modul,integer);
//                    $this->check_message($Message,'conductivity', $ID_Modul,integer);
//                    $this->check_message($Message,'temperature', $ID_Modul,float);
//                }    
                
                
            }
            
            
            
            if($mode==2){//falls nicht miflora empfangen wurde
                IPS_LogMessage("PIMQTT",'BLT_Temp_Sensor execute');
                //Message decodieren und in Variable schreiben 
                $Message=json_decode($Buffer->MSG);

                if($Message->Modul!=''){
                    $Modul=strval($Message->Modul);
                    $Modul_Ident=str_replace( ':' , '' , $Modul );
                    $this->SendDebug('Modul', $Message->Modul, 0);
                    $this->SendDebug('Modul ident', $Modul_Ident, 0);
                    $ID_Modul=@IPS_GetObjectIDByIdent($Modul_Ident, $this->ReadPropertyInteger('$ID_Cat_Devices'));
                    if($ID_Modul===FALSE){
                        $ID_Modul= IPS_CreateCategory();
                        IPS_SetName($ID_Modul, $Modul);
                        IPS_SetParent($ID_Modul, $this->ReadPropertyInteger('$ID_Cat_Devices'));
                        IPS_SetIdent($ID_Modul, $Modul_Ident);
                        IPS_LogMessage("PIMQTT",'Create Cat in'.$this->ReadPropertyInteger('$ID_Cat_Devices'));
                        IPS_LogMessage("PIMQTT",'Create Cat'.$Modul);
                    }
                    IPS_LogMessage("PIMQTT",'Buffer -> MSG  '.strval($Buffer->MSG));
                }
                if(fnmatch('*Temperatur*', strval($Buffer->MSG))){    
                    IPS_LogMessage("PIMQTT",'fnMatch Temperatur');
                    $ID_Temp=@IPS_GetObjectIDByIdent('Temperatur', $ID_Modul);
                    if($ID_Temp===FALSE){
                        $ID_Temp=$this->createVariable('Temperatur', $ID_Modul, 'RaumTemperature');
                    }
                    SetValueFloat($ID_Temp, floatval($Message->Temperatur));
                    $this->SendDebug('Set Temp', floatval($Message->Temperatur).'to'.$ID_Temp, 0);
                }   

                if(fnmatch('*Humidity*', strval($Buffer->MSG))){ 
                    IPS_LogMessage("PIMQTT",'fnMatch Humidity');
                    $ID_Humid=@IPS_GetObjectIDByIdent('Humidity', $ID_Modul);
                    if($ID_Humid===FALSE){
                        $ID_Humid=$this->createVariable('Humidity', $ID_Modul, 'Humidity');
                    }
                    SetValueFloat($ID_Humid, floatval($Message->Humidity));
                    $this->SendDebug('Set Humid', floatval($Message->Humidity).'to'.$ID_Humid, 0);
                }
                if(fnmatch('*Battery*', strval($Buffer->MSG))){ 
                    IPS_LogMessage("PIMQTT",'fnMatch Battery');
                    $ID_Batt=@IPS_GetObjectIDByIdent('Battery', $ID_Modul);
                    if($ID_Batt===FALSE){
                        $ID_Batt=$this->createVariable('Battery', $ID_Modul, 'Battery');
                    }
                    SetValueFloat($ID_Batt, floatval($Message->Battery));
                    $this->SendDebug('Set Humid', floatval($Message->Battery).'to'.$ID_Batt, 0);
                }
                
               if(fnmatch('*Time*', strval($Buffer->MSG))){ 
                    IPS_LogMessage("PIMQTT",'fnMatch Time');
                    $ID_Time=@IPS_GetObjectIDByIdent('Time', $ID_Modul);
                    if($ID_Time===FALSE){
                        $ID_Time=$this->createVariable('Time', $ID_Modul,'',3);
                    }
                    SetValueString($ID_Time, $Message->Time);
                    $this->SendDebug('Set Humid', floatval($Message->Time).'to'.$ID_Time, 0);
                }
            }
        }
    }
    
    
    private function createVariable($Name,$ParentID,$ProfilName,$type )
    {
       if(!isset($type)){
           $type=2;
       }
       $id= IPS_CreateVariable($type);
       IPS_SetName($id, $Name);
       IPS_SetParent($id, $ParentID);
       
       if(isset($ProfilName)){
           IPS_SetVariableCustomProfile($id, $ProfilName);
       }    
       
       IPS_SetIdent($id, $Name);
       return $id;
    }
    
    private function check_message($Message,$needle,$ID_Modul,$type)
    {
        if(!isset($type)){
            $type=1;
        }
        if (array_key_exists($needle, $Message)) {
            IPS_LogMessage("PIMQTT",$needle.'='.$Message[$needle]);
            $value=$Message[$needle];
            $ID_needle=@IPS_GetObjectIDByIdent($needle, $ID_Modul);
            if($ID_needle===FALSE){
                $ID_needle=$this->createVariable($needle, $ID_Modul,'',$type);
            }
            switch($type){
                case integer: SetValueInteger($ID_needle, intval($value)); break;
                case float: SetValueFloat($ID_needle, floatval($value)); break;
                default: SetValueInteger($ID_needle, intval($value)); break;
            }
            
        }
    }
    private function createCategory($Sensor) {
        $ID_Modul= IPS_CreateCategory();
        IPS_SetName($ID_Modul, $Sensor);
        IPS_SetParent($ID_Modul, $this->ReadPropertyInteger('$ID_Cat_Devices'));
        IPS_SetIdent($ID_Modul, $Sensor);
        IPS_LogMessage("PIMQTT",'Create Cat in'.$this->ReadPropertyInteger('$ID_Cat_Devices'));
        IPS_LogMessage("PIMQTT",'Create Cat'.$Sensor);
        return ($ID_Modul);
    }
    
    private function proceed_miflora($Buffer) {
        $Topic = $Buffer->TOPIC;
        IPS_LogMessage("PIMQTT",'miflora execute!');
        IPS_LogMessage("PIMQTT",'Buffer -> MSG  '.strval($Buffer->MSG));
        if(fnmatch('*$announce*', $Topic)){    
            IPS_LogMessage("PIMQTT",'announce received: '.$Topic);
            $Message=json_decode($Buffer->MSG,TRUE);
            if($Message==''){
                IPS_LogMessage("PIMQTT",'Message leer ');
                return(0);
            }
            print_r($Message);
//                    IPS_LogMessage("PIMQTT",'Name: '.strval($Message[1]));
//            if (array_key_exists('name_pretty', $Message)) {
//                IPS_LogMessage("PIMQTT",'Namen gefunden!!! '.$Message['name_pretty']);
//                $Sensor=$Message['name_pretty'];
//            }
//            else{
//                IPS_LogMessage("PIMQTT",'Name nicht gefunden! ');
//            }
        }
        else {
            $position=strpos($Topic,'/')+1;        
            $Sensor= substr($Topic,$position);                  
            IPS_LogMessage("PIMQTT",'Sensor Name= '.$Sensor);
            $Modul_Ident=$Sensor;
            $ID_Modul=@IPS_GetObjectIDByIdent($Modul_Ident, $this->ReadPropertyInteger('$ID_Cat_Devices'));
            if($ID_Modul===FALSE){
                $ID_Modul=$this->createCategory($Sensor);
            }
            $Message=json_decode($Buffer->MSG,TRUE);
            $this->check_message($Message,'battery', $ID_Modul,integer);
            $this->check_message($Message,'light', $ID_Modul,integer);
            $this->check_message($Message,'moisture', $ID_Modul,integer);
            $this->check_message($Message,'conductivity', $ID_Modul,integer);
            $this->check_message($Message,'temperature', $ID_Modul,float);
        }    
    }
    
}
