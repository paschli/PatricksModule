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
   
    //$ClickDetectId = $this->RegisterVariableBoolean('ClickDetect', 'KlickErkannt','', 1); //Boolean anlegen, der bei erkennung gesetzt wird 
    
//Inhalt für Skript erzeugen, das bei Erkennung ausgeführt wird 
/*  $stringInhalt="<?\n IPS_LogMessage('DBLClick_Script','Starte User_Script.....................'); \n SetValueBoolean($DBLClickDetectId, FALSE); \n//Start your code here\n\n?>"; */
    //Skript anlegen
//    $scriptID = $this->RegisterScript('SCRIPT', 'DBLClickScript',$stringInhalt,2);
//    $presentId = $this->RegisterVariableInteger('PRESENT_SINCE', 'Anwesend seit', '~UnixTimestamp', 3);
//    $absentId = $this->RegisterVariableInteger('ABSENT_SINCE', 'Abwesend seit', '~UnixTimestamp', 3);
//    $nameId = $this->RegisterVariableString('NAME', 'Name_Device', '', 2);
//    IPS_SetIcon($this->GetIDForIdent('DBLClickDetect'), 'Motion');
//    IPS_SetIcon($this->GetIDForIdent('SCRIPT'), 'Keyboard');
    
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
  
  protected function CheckKatTasten($Key,$inst_id) {
      $inst_name=IPS_GetObject($inst_id)['ObjectName'];
      IPS_LogMessage('ONEClick-'.$inst_name,"CheckKatTasten");
      $KeyID=@IPS_GetCategoryIDByName($Key,$inst_id);
      if(!$KeyID){
          IPS_LogMessage('ONEClick',"Erstelle Kategorie für Taste: ".$Key);
          $KeyID=IPS_CreateCategory();
          IPS_SetParent($KeyID, $inst_id);
          IPS_SetName($KeyID, $Key);
      }
      IPS_LogMessage('ONEClick',"Tasten-Kategorie OK");
      return $KeyID;
  }
  
  protected function CheckSkript($source_taste,$KeyCatID) {
      IPS_LogMessage('ONEClick-'.$inst_name,"CheckSkript");
//Skript für erkannte Taste ermitteln oder erstellen
        $scriptID=@IPS_GetScriptIDByName("Taste_".$source_taste, $KeyCatID);
//Falls Skript noch nicht vorhanden
        if(!$scriptID){
            $stringInhalt="<?\n IPS_LogMessage('ONEClick_Script'.'$source_taste','Starte User_Script.....................'); \n//Start your code here\n\n?>";
            IPS_LogMessage('ONEClick',"Erstelle Skript für Taste: ".$Key);
            $scriptID= IPS_CreateScript(0);
            IPS_SetParent($scriptID, $KeyCatID);
            IPS_SetName($scriptID, "Taste_".$source_taste);
            IPS_SetScriptContent($scriptID, $stringInhalt);   
        }
        IPS_LogMessage('ONEClick',"Skript OK");
        
        return $scriptID;
  }
  
  
  public function Check() {
    //IPS_LogMessage('DBLClick',"Setze Semaphore");
    if(IPS_SemaphoreEnter('ONEClick', 1000)) {
//ID und Wert von "command" ermitteln
      $stringID=$this->ReadPropertyInteger('idSourceInstance');
      $string=GetValueString($stringID);
//ID der aktuellen Instanz ermitteln   
      $inst_id=IPS_GetParent($stringID);	
      $inst_info= IPS_GetObject($inst_id);
      $inst_name=$inst_info['ObjectName'];
//Auswertung 
      IPS_LogMessage('ONEClick-'.$inst_name,"Starte Check.(".substr($string, -3).")....................");
//Tastendruck erkennen
      
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
      }
//Sender-Taste ermitteln (Tabelle und Tastennummer
      $source_table= substr($string,1,1);
      $source_button= substr($string, 2,1);
//Sender-Tabelle nach LCN in Buchstaben wandeln
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
          IPS_SemaphoreLeave('ONEClick');
          exit ();
          break;
      }
      $source_taste=$source_table.$source_button.$TastenDruck;
      IPS_LogMessage('ONEClick-'.$inst_name,"Taste =".$source_taste);
//Kategorie prüfen
      IPS_LogMessage('ONEClick-'.$inst_name,"Kategorien prüfen");
      $CatID= $this->CheckKategorie($inst_id);
      $KeyCatID= $this->CheckKatTasten($source_taste,$CatID);
      
//Ermitteln ob doppelter Tastendruck in Zeit "DBLCLickTime" vorliegt
//ID der Bool-Variable für Doppelklick
      //$ClickDetectID=$this->GetIDForIdent('ClickDetect');
      //$instancethisID= IPS_GetParent($ClickDetectID);
//Eigenschaften der "command" Variable ermitteln 
//      $stringInfo= IPS_GetVariable($stringID);
//Zeit des letzten Tastendrucks ermitteln
//      $AktuelleZeit = $stringInfo['VariableUpdated'];//Zeitpunkt des aktuellen Updates
//Zeit des vorletzten Tastendrucks lesen
//      $lastUpdID=$this->GetIDForIdent('LASTUPD');// ID für LastUpd suchen 
//      $lastUpdValue= GetValueInteger($lastUpdID);// Wert für LastUpd lesen
//Eingestellte Grenze für Doppelklickerkennung lesen      
//      $DBLClickTime= $this->ReadPropertyInteger('DBLClickTime');
      IPS_LogMessage('ONEClick-'.$inst_name,"Werte eingelesen");
      
//letzte Tastenbedienung speichern
//      SetValueInteger($lastUpdID, $AktuelleZeit);
//Debugausgaben
//      IPS_LogMessage('DBLClick-'.$inst_name,"Aktuelle Zeit =".$AktuelleZeit);
//      IPS_LogMessage('DBLClick-'.$inst_name,"Letzer Click bei =".$lastUpdValue);
//      IPS_LogMessage('DBLClick-'.$inst_name,"Differenz =".($AktuelleZeit-$lastUpdValue));
//Überprüfen ob Zeit zwischen vorletzter und letzter Bedienung kleiner Grenze ist
//      if(($AktuelleZeit-$lastUpdValue)<=$DBLClickTime){ 
	//SetValueBoolean($ClickDetectID, true);
        //IPS_LogMessage('ONEClick',"Klick erkannt");
        $scriptID= $this->CheckSkript($source_taste,$KeyCatID);
            
        IPS_RunScript($scriptID);
      }
      else{
	//SetValueBoolean($DBLClickDetectID, false);
        IPS_LogMessage('ONEClick-'.$inst_name,"Klick nicht erkannt");
      }
      IPS_SemaphoreLeave('ONEClick');
//     } 
//     else {
//      IPS_LogMessage('DBLClick', 'Semaphore Timeout');
//    }
   }
} 
?>
