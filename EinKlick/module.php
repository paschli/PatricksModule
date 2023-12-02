<?
//Modul überwacht die Host Kommandos eises Moduls (Taster), die in einer Variable abgelegt werden. 
//Für die erkannte Taste und das Ereignis wird ein Skript gestartet. 
//Falls das Skript nicht vorhanden ist, wird es erstellt.
//$this->SendDebug (string $Meldungsname, string $Daten, int $Format)
//$this->SendDebug ('OnButonClick', string $Daten, 0);
class ONEClick extends IPSModule {
  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('idSourceInstance', 0);  //Id der zu beobachtenden Variable
    $this->RegisterPropertyInteger('PropertyCategoryID', 0);//Id der zu beobachtenden Variable
    $this->RegisterPropertyString('SourceList', 0);            //Id der zu beobachtenden Variable
  }
    
  public function ApplyChanges() {
    parent::ApplyChanges();
    //if($this->ReadPropertyInteger('idSourceInstance')!=0){
    //	$this->RegisterEvent('OnVariableUpdate', 0, 'ONEC_Check($id)',$this->ReadPropertyInteger('idSourceInstance'));
    //}
    $arrString = $this->ReadPropertyString("SourceList");
    $arr = json_decode($arrString,true);
    foreach($arr as $value){
        $string_id=$value['SourceStringID'];
        //IPS_LogMessage('ONEClick',"Liste Element =".$string_id);
        $this->SendDebug ('ApplyChanges', "Element aus der Liste=".$string_id, 0);
        $this->RegisterEvent('OnChange_'.$value['SourceStringID'], 0, 'ONEC_Check($id,$trigger)',$string_id);
    }
  }
  
 
  protected function RegisterEvent($ident, $interval, $script, $trigger) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
      IPS_DeleteEvent($id);
      $id = 0;
    }
    if (!$id) {
      $id = IPS_CreateEvent(0);
      IPS_SetEventTrigger($id, 0, $trigger); //Bei Update von der gewählten Variable
      IPS_SetEventActive($id, true);             //Ereignis aktivieren
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
    }
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, "\$id = \$_IPS['TARGET'];\n\$trigger = \$_IPS['VARIABLE'];\n$script;");
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
  }
 
  protected function checkMainCat($inst_id) {
       $this->SendDebug ('checkMainCat', "Suche: Kategorie Tasten", 0);
       $inst_name=IPS_GetObject($inst_id)['ObjectName'];
//Ort für die Kategorie
       $targetCat_id=$this->ReadPropertyInteger('PropertyCategoryID');
       $CatID=@IPS_GetCategoryIDByName("Tasten",$targetCat_id);

       if(!$CatID){ //falls noch nicht angelegt ->
           $CatID=IPS_CreateCategory();
           IPS_SetParent($CatID, $this->ReadPropertyInteger('PropertyCategoryID'));
           IPS_SetName($CatID, "Tasten");
           $this->SendDebug ('CheckKategorie', "Angelegt: Kategorie Tasten", 0);
       }
       $this->SendDebug ('CheckKategorie', "OK: Kategorie", 0);
       return $CatID;
   }
    
  protected function checkTypeCat($Typ,$mainCat_id) {
       $this->SendDebug ('checkTypeCat', "Suche: Kategorie ".$Typ, 0);
       $typeCat_id=@IPS_GetCategoryIDByName($Typ,$mainCat_id);
       if(!$typeCat_id){
           $typeCat_id=IPS_CreateCategory();
           IPS_SetParent($typeCat_id, $mainCat_id);
           IPS_SetName($typeCat_id, $Typ);
           $this->SendDebug ('checkTypeCat', "Angelegt: Kategorie ".$Typ, 0);
       }
       $this->SendDebug ('checkTypeCat', "OK: Kategorie ".$Typ, 0);
       return $typeCat_id;
    }
    
  protected function checkKeyCat($Key,$typeCat_id) {
      $this->SendDebug ('checkKeyCat', "Suche: Kategorie ".$Key, 0);
      $keyCat_id=@IPS_GetCategoryIDByName($Key,$typeCat_id);
      if(!$keyCat_id){
          $keyCat_id=IPS_CreateCategory();
          IPS_SetParent($keyCat_id, $typeCat_id);
          IPS_SetName($keyCat_id, $Key);
          $this->SendDebug ('checkKeyCat', "Angelegt: Kategorie ".$Key, 0);
      }
      $this->SendDebug ('checkKeyCat', "OK: Kategorie ".$Key, 0);
      return $keyCat_id;
  }
  
  protected function CheckSkript($tasteCat,$source_taste) {
        $this->SendDebug ('CheckSkript', "Suche: Script ".$source_taste, 0);
//Skript für erkannte Taste ermitteln oder erstellen
        $scriptID=@IPS_GetScriptIDByName($source_taste, $tasteCat);
//Falls Skript noch nicht vorhanden
        if(!$scriptID){
            $stringInhalt="<?\n IPS_LogMessage('ONEClick_Script'.'$source_taste','Starte User_Script..'); \n//Start your code here\n\n?>";
            $scriptID= IPS_CreateScript(0);
            IPS_SetParent($scriptID, $tasteCat);
            IPS_SetName($scriptID, $source_taste);
            IPS_SetScriptContent($scriptID, $stringInhalt);  
            $this->SendDebug ('CheckSkript', "Angelegt: Script ".$source_taste, 0);
        }
       $this->SendDebug ('CheckSkript', "OK: Script ".$source_taste, 0);
        
        return $scriptID;
  }
  
protected function decodeLCNKey($string){
//Prüfe, welches LCN-Tasten-Event gekommen ist
    if(substr($string, -3) == "111"){ //kurzer Tastendruck
        $TastenDruck="_kurz";
    }
    else if(substr($string, -3) == "222"){ //langer Tastendruck
        $TastenDruck="_lang";
    }
    else if(substr($string, -3) == "123"){ //Loslassen nach langem Tastedruck
        $TastenDruck="_los";
    }
    else {
        $TastenDruck=0;
    }
    $this->SendDebug ('decodeLCNKey', "Erkannt: LCN-Tastendruck ".$TastenDruck, 0);
    return $TastenDruck;
}

protected function decodeLCNtable($source_table){
    
    switch ($source_table){
        case "1": $source_table="A";
          break;
        case "2": $source_table="B";
          break;
        case "3": $source_table="C";
          break;
        case "4": $source_table="D";
          break;
        default : IPS_LogMessage('ONEClick',"Tastentabelle nicht erkannt -> Exit");
          $source_table=0;
          break;
    }
    
    $this->SendDebug ('decodeLCNtable', "Erkannt: LCN-Tabelle ".$source_table, 0);
    return $source_table;
}

protected function handleLCN($string,$inst_info){
//bestimme ID und Name
    $inst_id=$inst_info['ObjectID'];
    $inst_name=$inst_info['ObjectName'];
    
//Sender-Taste ermitteln (Tabelle und Tastennummer)
    $TastenDruck = $this->decodeLCNKey($string);
    $source_table= $this->decodeLCNtable(substr($string,1,1));
    if(!$source_table || !$TastenDruck ){//Falls die Tabelle oder Tastendruck nicht erkannt wurde
        return 0;
    }
    $source_button= substr($string, 2,1);
//Taste aus Infos zusammensetzen
    $source_taste=$source_table.$source_button.$TastenDruck;
    $Key=$source_table.$source_button;
    IPS_LogMessage('ONEClick-'.$inst_name,"Taste =".$source_taste);
    
//Skript für Tastendruck finden oder erzeugen
    
    $mainCat=$this->checkMainCat($inst_id); // Id der Main Category
    $typeCat=$this->checkTypeCat('LCN',$mainCat); // ID der Type Category (hier LCN)
    $tasteCat=$this->checkKeyCat($Key,$typeCat); // ID des Keys
    $script_id=$this->CheckSkript($tasteCat,'Taste_'.$source_taste); // ID des Scripts (je nach kurz, lang, stop usw.
    
    IPS_RunScript($script_id);
    return 1;
        

}

 protected function handleZigbee($string,$inst_info){
    //bestimme ID und Name
        $inst_id=$inst_info['ObjectID'];
        $inst_name=$inst_info['ObjectName'];
        
        $Key=$string;
        $this->SendDebug ('handleZigbee', "Erkannt: Zigbee ".$Key, 0);
        
    //Skript für Tastendruck finden oder erzeugen
        
        $mainCat=$this->checkMainCat($inst_id); // Id der Main Category
        $typeCat=$this->checkTypeCat('Zigbee',$mainCat); // ID der Type Category (hier LCN)
        //$tasteCat=$this->checkKeyCat($Key,$typeCat); // ID des Keys
        $script_id=$this->CheckSkript($typeCat,'Taste_'.$string); // ID des Scripts (je nach kurz, lang, stop usw.
        
        IPS_RunScript($script_id);
        return 1;
            

    }
  
 public function Check($trigger) {
  if(IPS_SemaphoreEnter('ONEClick', 1000)) {
//ID der aktuellen Instanz ermitteln
      $stringID=$trigger;
      $inst_id=IPS_GetParent($stringID);
      $inst_info= IPS_GetObject($inst_id);
      $inst_name=$inst_info['ObjectName'];
//ID und Wert von "command" ermitteln
      //$stringID=$this->ReadPropertyInteger('idSourceInstance');
      $this->SendDebug ('Check', "Starte Check für Nachricht =".$trigger."....................", 0);
      $string=GetValueString($stringID);
//Ort für die Kategorie
      $targetCat_id=$this->ReadPropertyInteger('PropertyCategoryID');

//Auswertung 
      $this->SendDebug ('Check', "String =".$string, 0);
//Tastentyp erkennen

      if((ctype_digit($string)) && (strlen($string)==6)) {//falls nur Zahlen Empfangen wurden und die Länge 6 ist
        $this->SendDebug ('Check', "LCN erkannt", 0);
        $type='LCN';
        $result=$this->handleLCN($string,$inst_info);
      }
      else if(ctype_alpha($string)) {//falls nur Zahlen Empfangen wurden und die Länge 6 ist
        $type='Zigbee';
          $this->SendDebug ('Check', "Zigbee erkannt", 0);
        $result=$this->handleZigbee($string,$inst_info);
      }
  }

    
  
  else{
      $this->SendDebug ('Check', "Event nicht erkannt", 0);
  }
  IPS_SemaphoreLeave('ONEClick');
 }
}
?>
