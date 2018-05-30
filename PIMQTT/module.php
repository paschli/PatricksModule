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
            }
            if(fnmatch('*BLT_Temp_Sensor*', $Topic)){    
                IPS_LogMessage("PIMQTT",'BLT_Temp_Sensor received: '.$Topic);
                $mode=2;
            }
            
            if(!$mode){
                IPS_LogMessage("PIMQTT",'unknown topic!');
            }
            
            if($mode==1){
                IPS_LogMessage("PIMQTT",'miflora execute!');
                IPS_LogMessage("PIMQTT",'Buffer -> MSG  '.strval($Buffer->MSG));
                if(fnmatch('*$announce*', $Topic)){    
                    IPS_LogMessage("PIMQTT",'announce received: '.$Topic);
                    array $Message=json_decode($Buffer->MSG,TRUE);
                    if($Message==''){
                        IPS_LogMessage("PIMQTT",'Message leer ');
                        return(0);
                    }
//                    IPS_LogMessage("PIMQTT",'Name: '.strval($Message[1]));
                    if (array_key_exists('Sensor2', $Message)) {
                        IPS_LogMessage("PIMQTT",'Gefunden!!! ');
                    }
                    else{
                        IPS_LogMessage("PIMQTT",'Nicht Gefunden! ');
                    }
                        
                    $Sensor=strval($Message->name_pretty);
                    IPS_LogMessage("PIMQTT",'Sensor Name= '.$Sensor);
//                    $ID_Modul=@IPS_GetObjectIDByIdent($Modul_Ident, $this->ReadPropertyInteger('$ID_Cat_Devices'));
//                    if($ID_Modul===FALSE){
//                        $ID_Modul= IPS_CreateCategory();
//                        IPS_SetName($ID_Modul, $Modul);
//                        IPS_SetParent($ID_Modul, $this->ReadPropertyInteger('$ID_Cat_Devices'));
//                        IPS_SetIdent($ID_Modul, $Modul_Ident);
//                        IPS_LogMessage("PIMQTT",'Create Cat in'.$this->ReadPropertyInteger('$ID_Cat_Devices'));
//                        IPS_LogMessage("PIMQTT",'Create Cat'.$Modul);
//                    }
                    
                }
            }
            
            
            
            if($mode==2){//falls nicht miflora empfangen wurde
                IPS_LogMessage("PIMQTT",'BLT_Temp_Sensor execute');
                //Message decodieren und in Variable schreiben 
                $Message=json_decode($Buffer->MSG);

                if($Message->Modul!=''){
                    $Modul=strval($Message->Modul);
                    $Modul_Ident=str_replace ( ':' , '' , $Modul );
                    $this->SendDebug('Modul', $Message->Modul, 0);
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
                    IPS_LogMessage("PIMQTT",'fnMatch OK');
                    $ID_Temp=@IPS_GetObjectIDByIdent('Temperatur', $ID_Modul);
                    if($ID_Temp===FALSE){
                        $ID_Temp=$this->createVariable('Temperatur', $ID_Modul, 'RaumTemperature');
                    }
                    SetValueFloat($ID_Temp, floatval($Message->Temperatur));
                }   

                if(fnmatch('*Humidity*', strval($Buffer->MSG))){ 
                    IPS_LogMessage("PIMQTT",'fnMatch OK');
                    $ID_Humid=@IPS_GetObjectIDByIdent('Humidity', $ID_Modul);
                    if($ID_Humid===FALSE){
                        $ID_Humid=$this->createVariable('Humidity', $ID_Modul, 'Humidity');
                    }
                    SetValueFloat($ID_Humid, floatval($Message->Humidity));
                }
                if(fnmatch('*Battery*', strval($Buffer->MSG))){ 
                    IPS_LogMessage("PIMQTT",'fnMatch OK');
                    $ID_Batt=@IPS_GetObjectIDByIdent('Battery', $ID_Modul);
                    if($ID_Batt===FALSE){
                        $ID_Batt=$this->createVariable('Battery', $ID_Modul, 'Battery');
                    }
                    SetValueFloat($ID_Batt, floatval($Message->Battery));
                }
            }
        }
    }
    
    
    private function createVariable($Name,$ParentID,$ProfilName)
    {
       $id= IPS_CreateVariable(2);
       IPS_SetName($id, $Name);
       IPS_SetParent($id, $ParentID);
       IPS_SetVariableCustomProfile($id, $ProfilName);
       IPS_SetIdent($id, $Name);
       return $id;
    }
}
