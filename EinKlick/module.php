<?
//Modul überwacht die Host Kommandos eises Moduls (Taster), die in einer Variable abgelegt werden. 
//Für die erkannte Taste und das Ereignis wird ein Skript gestartet. 
//Falls das Skript nicht vorhanden ist, wird es erstellt.
class ONEClick extends IPSModule {
  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('idSourceInstance', 0);//Id der zu beobachtenden Variable	
  }
    
  public function ApplyChanges() {
    parent::ApplyChanges();
    if($this->ReadPropertyInteger('idSourceInstance')!=0){  
    	$this->RegisterEvent('OnVariableUpdate', 0, 'ONEC_Check($id)');
    }
  }
  
 
  protected function RegisterEvent($ident, $interval, $script) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
      IPS_DeleteEvent($id);
      $id = 0;
    }
    if (!$id) {
      $id = IPS_CreateEvent(0);
      IPS_SetEventTrigger($id, 0, $this->ReadPropertyInteger('idSourceInstance')); //Bei Update von der gewählten Variable 
      IPS_SetEventActive($id, true);             //Ereignis aktivieren
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
    }
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, "\$id = \$_IPS['TARGET'];\n$script;");
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
  }
 
   protected function CheckKategorie(int $inst_id) {
      $inst_name=IPS_GetObject($inst_id)['ObjectName'];
      IPS_LogMessage('ONEClick-'.$inst_name,"CheckKategorie");
      $CatID=@IPS_GetCategoryIDByName("Tasten",$inst_id);
      if(!$CatID){
          IPS_LogMessage('ONEClick-'.$inst_name,"Kategorie neu anlegen");
          IPS_LogMessage('ONEClick',"Erstelle Kategorie Tasten ");
          $CatID=IPS_CreateCategory();
          IPS_SetParent($CatID, $inst_id);
          IPS_SetName($CatID, "Tasten");
      }
      IPS_LogMessage('ONEClick',"Haupt-Kategorie OK");
      return $CatID;
  }
  
  protected function checkMainCat($inst_id) {

       $CatID=@IPS_GetCategoryIDByName("Tasten",$inst_id);

       if(!$CatID){ //falls noch nicht angelegt ->
           IPS_LogMessage('ONEClick-'.$inst_name,"Kategorie neu anlegen");
           IPS_LogMessage('ONEClick',"Erstelle Kategorie Tasten ");
           $CatID=IPS_CreateCategory();
           IPS_SetParent($CatID, $inst_id);
           IPS_SetName($CatID, "Tasten");
       }
       
       IPS_LogMessage('ONEClick',"Haupt-Kategorie OK");
       return $CatID;
   }
    
  protected function checkTypeCat($Typ,$mainCat_id) {
       
       $typeCat_id=@IPS_GetCategoryIDByName($Typ,$mainCat_id);
       if(!$typeCat_id){
           IPS_LogMessage('ONEClick',"Erstelle Kategorie für Typ: ".$Typ);
           $typeCat_id=IPS_CreateCategory();
           IPS_SetParent($typeCat_id, $mainCat_id);
           IPS_SetName($typeCat_id, $Typ);
       }
       IPS_LogMessage('ONEClick',"Tasten-Kategorie OK");
       return $typeCat_id;
    }
    
  protected function checkKeyCat($Key,$typeCat_id) {
      $keyCat_id=@IPS_GetCategoryIDByName($Key,$typeCat_id);
      if(!$keyCat_id){
          IPS_LogMessage('ONEClick',"Erstelle Kategorie für Taste: ".$Key);
          $keyCat_id=IPS_CreateCategory();
          IPS_SetParent($keyCat_id, $typeCat_id);
          IPS_SetName($keyCat_id, $Key);
      }
      IPS_LogMessage('ONEClick',"Tasten-Kategorie OK");
      return $keyCat_id;
  }
  
  protected function CheckSkript($tasteCat,$source_taste) {
//Skript für erkannte Taste ermitteln oder erstellen
        $scriptID=@IPS_GetScriptIDByName($source_taste, $tasteCat);
//Falls Skript noch nicht vorhanden
        if(!$scriptID){
            $stringInhalt="<?\n IPS_LogMessage('ONEClick_Script'.'$source_taste','Starte User_Script.....................'); \n//Start your code here\n\n?>";
            IPS_LogMessage('ONEClick',"Erstelle Skript für Taste: ".$source_taste);
            $scriptID= IPS_CreateScript(0);
            IPS_SetParent($scriptID, $tasteCat);
            IPS_SetName($scriptID, $source_taste);
            IPS_SetScriptContent($scriptID, $stringInhalt);   
        }
        IPS_LogMessage('ONEClick',"Skript OK");
        
        return $scriptID;
  }
  
protected function decodeLCNKey($string){
//Prüfe, welches LCN-Tasten-Event gekommen ist
    if(substr($string, -3) == "111"){ //kurzer Tastendruck
        IPS_LogMessage('ONEClick',"Kurzer Tatendruck ");
        $TastenDruck="_kurz";
    }
    else if(substr($string, -3) == "222"){ //langer Tastendruck
        IPS_LogMessage('ONEClick',"Langer Tatendruck ");
        $TastenDruck="_lang";
    }
    else if(substr($string, -3) == "123"){ //Loslassen nach langem Tastedruck
        IPS_LogMessage('ONEClick',"Loslassen ");
        $TastenDruck="_los";
    }
    else {
        IPS_LogMessage('ONEClick',"Tastendruck nicht erkannt (".substr($string, -3).") ");
        $TastenDruck=0;
    }
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
    IPS_LogMessage('ONEClick-decodeLCNtable',"Tabelle =".$source_table);
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
        
    
        IPS_LogMessage('ONEClick-'.$inst_name,"Taste =".$string);
        
    //Skript für Tastendruck finden oder erzeugen
        
        $mainCat=$this->checkMainCat($inst_id); // Id der Main Category
        $typeCat=$this->checkTypeCat('Zigbee',$mainCat); // ID der Type Category (hier LCN)
        $tasteCat=$this->checkKeyCat($Key,$typeCat); // ID des Keys
        $script_id=$this->CheckSkript($tasteCat,'Taste_'.$string); // ID des Scripts (je nach kurz, lang, stop usw.
        
        IPS_RunScript($script_id);
        return 1;
            

    }
  
 public function Check() {
  if(IPS_SemaphoreEnter('ONEClick', 1000)) {
//ID und Wert von "command" ermitteln
      $stringID=$this->ReadPropertyInteger('idSourceInstance');
      $string=GetValueString($stringID);
//ID der aktuellen Instanz ermitteln   
      $inst_id=IPS_GetParent($stringID);	
      $inst_info= IPS_GetObject($inst_id);
      $inst_name=$inst_info['ObjectName'];
//Auswertung 
      IPS_LogMessage('ONEClick-'.$inst_name,"Starte Check von Nachricht =".$string."....................");
//Tastentyp erkennen

      if((ctype_digit($string)) && (strlen($string)==6)) {//falls nur Zahlen Empfangen wurden und die Länge 6 ist
        IPS_LogMessage('ONEClick-'.$inst_name,"LCN erkannt");
        $type='LCN';
        $result=$this->handleLCN($string,$inst_info);
      }
      else if(ctype_alpha($string)) {//falls nur Zahlen Empfangen wurden und die Länge 6 ist
        $type='Zigbee';
        IPS_LogMessage('ONEClick-'.$inst_name,"Zigbee erkannt");
        $result=$this->handleZigbee($string,$inst_info);
      }
  }

    
  
  else{
    IPS_LogMessage('ONEClick-'.$inst_name,"Klick nicht erkannt");
  }
  IPS_SemaphoreLeave('ONEClick');
 }
}
?>
