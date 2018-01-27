<?php

namespace ClansPE;

use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\event\Listener;
use pocketmine\level\Level;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\block\Snow;
use pocketmine\math\Vector3;

class ClanMain extends PluginBase implements Listener {
    
    public $db;
    public $prefs;
    public $war_req = [];
    public $wars = [];
    public $war_players = [];
    public $antispam;
    public $purechat;
    public $clanChatActive = [];
    public $allyChatActive = [];
    public function onEnable() {
        @mkdir($this->getDataFolder());
        if (!file_exists($this->getDataFolder() . "BannedNames.txt")) {
            $file = fopen($this->getDataFolder() . "BannedNames.txt", "w");
            $txt = "Admin:admin:Staff:staff:Owner:owner:Builder:builder:Op:OP:op";
            fwrite($file, $txt);
        }
        $this->getServer()->getPluginManager()->registerEvents(new ClanListener($this), $this);
        $this->antispam = $this->getServer()->getPluginManager()->getPlugin("AntiSpamPro");
        if (!$this->antispam) {
            $this->getLogger()->info("Add AntiSpamPro to ban rude Faction names");
        }
        $this->purechat = $this->getServer()->getPluginManager()->getPlugin("PureChat");
        if (!$this->purechat) {
            $this->getLogger()->info("Add PureChat to display Clan ranks in chat");
        }
        $this->cCommand = new ClanCommands($this);
        $this->prefs = new Config($this->getDataFolder() . "Prefs.yml", CONFIG::YAML, array(
            "MaxClanNameLength" => 15,
            "MaxPlayersPerClan" => 30,
            "OnlyLeadersAndOfficersCanInvite" => true,
            "PowerGainedPerPlayerInClan" => 50,
            "PowerGainedPerKillingAnEnemy" => 10,
            "PowerGainedPerAlly" => 100,
            "AllyLimitPerClan" => 5,
            "TheDefaultPowerEveryClanStartsWith" => 0,
            "AllowChat" => true,
            "AllowClanPvp" => false,
            "AllowAlliedPvp" => false
        ));
        $this->db = new \SQLite3($this->getDataFolder() . "ClansPE.db");
        $this->db->exec("CREATE TABLE IF NOT EXISTS master (player TEXT PRIMARY KEY COLLATE NOCASE, clan TEXT, rank TEXT);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS confirm (player TEXT PRIMARY KEY COLLATE NOCASE, clan TEXT, invitedby TEXT, timestamp INT);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS alliance (player TEXT PRIMARY KEY COLLATE NOCASE, clan TEXT, requestedby TEXT, timestamp INT);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS motdrcv (player TEXT PRIMARY KEY, timestamp INT);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS motd (clan TEXT PRIMARY KEY, message TEXT);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS strength(clan TEXT PRIMARY KEY, power INT);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS allies(ID INT PRIMARY KEY,clan1 TEXT, clan2 TEXT);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS enemies(ID INT PRIMARY KEY,clan1 TEXT, clan2 TEXT);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS alliescountlimit(clan TEXT PRIMARY KEY, count INT);");
        try{
            $this->db->exec("ALTER TABLE plots ADD COLUMN world TEXT default null");
            Server::getInstance()->getLogger()->info(TextFormat::GREEN . "ClansPE: Added 'world' column to plots");
        }catch(\ErrorException $ex){
        }
    }
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) :bool {
        return $this->cCommand->onCommand($sender, $command, $label, $args);
    }
    public function setEnemies($clan1, $clan2) {
        $stmt = $this->db->prepare("INSERT INTO enemies (clan1, clan2) VALUES (:clan1, clan2);");
        $stmt->bindValue(":clan1", $clan1);
        $stmt->bindValue(":clan2", $clan2);
        $stmt->execute();
    }
    public function areEnemies($clan1, $clan2) {
        $result = $this->db->query("SELECT ID FROM enemies WHERE clan1 = '$clan1' AND clan2 = '$clan2';");
        $resultArr = $result->fetchArray(SQLITE3_ASSOC);
        if (empty($resultArr) == false) {
            return true;
        }
    }
    public function isInClan($player) {
        $result = $this->db->query("SELECT player FROM master WHERE player='$player';");
        $array = $result->fetchArray(SQLITE3_ASSOC);
        return empty($array) == false;
    }
    public function getClan($player) {
        $clan = $this->db->query("SELECT faction FROM master WHERE player='$player';");
        $clanArray = $clan->fetchArray(SQLITE3_ASSOC);
        return $clanArray["clan"];
    }
    public function setClanPower($clan, $power) {
        if ($power < 0) {
            $power = 0;
        }
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO strength (clan, power) VALUES (:clan, :power);");
        $stmt->bindValue(":clan", $clan);
        $stmt->bindValue(":power", $power);
        $stmt->execute();
    }
    public function setAllies($clan1, $clan2) {
        $stmt = $this->db->prepare("INSERT INTO allies (clan1, clan2) VALUES (:clan1, :clan2);");
        $stmt->bindValue(":clan1", $clan1);
        $stmt->bindValue(":clan2", $clan2);
        $stmt->execute();
    }
    public function areAllies($clan1, $clan2) {
        $result = $this->db->query("SELECT ID FROM allies WHERE clan1 = '$clan1' AND clan2 = '$clan2';");
        $resultArr = $result->fetchArray(SQLITE3_ASSOC);
        if (empty($resultArr) == false) {
            return true;
        }
    }
    public function updateAllies($clan) {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO alliescountlimit(clan, count) VALUES (:clan, :count);");
        $stmt->bindValue(":clan", $clan);
        $result = $this->db->query("SELECT ID FROM allies WHERE clan1='$clan';");
        $i = 0;
        while ($resultArr = $result->fetchArray(SQLITE3_ASSOC)) {
            $i = $i + 1;
        }
        $stmt->bindValue(":count", (int) $i);
        $stmt->execute();
    }
    public function getAlliesCount($clan) {
        $result = $this->db->query("SELECT count FROM alliescountlimit WHERE clan = '$clan';");
        $resultArr = $result->fetchArray(SQLITE3_ASSOC);
        return (int) $resultArr["count"];
    }
    public function getAlliesLimit() {
        return (int) $this->prefs->get("AllyLimitPerClan");
    }
    public function deleteAllies($clan1, $clan2) {
        $stmt = $this->db->prepare("DELETE FROM allies WHERE clan1 = '$clan1' AND clan2 = '$clan2';");
        $stmt->execute();
    }
    public function getClanPower($clan) {
        $result = $this->db->query("SELECT power FROM strength WHERE clan = '$clan';");
        $resultArr = $result->fetchArray(SQLITE3_ASSOC);
        return (int) $resultArr["power"];
    }
    public function addClanPower($clan, $power) {
        if ($this->getClanPower($clan) + $power < 0) {
            $power = $this->getClanPower($clan);
        }
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO strength (clan, power) VALUES (:clan, :power);");
        $stmt->bindValue(":clan", $clan);
        $stmt->bindValue(":power", $this->getClanPower($clan) + $power);
        $stmt->execute();
    }
    public function subtractClanPower($clan, $power) {
        if ($this->getClanPower($clan) - $power < 0) {
            $power = $this->getClanPower($clan);
        }
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO strength (clan, power) VALUES (:clan, :power);");
        $stmt->bindValue(":clan", $clan);
        $stmt->bindValue(":power", $this->getClanPower($clan) - $power);
        $stmt->execute();
    }
    public function isLeader($player) {
        $clan = $this->db->query("SELECT rank FROM master WHERE player='$player';");
        $clanArray = $clan->fetchArray(SQLITE3_ASSOC);
        return $clanArray["rank"] == "Leader";
    }
    public function isOfficer($player) {
        $clan = $this->db->query("SELECT rank FROM master WHERE player='$player';");
        $clanArray = $clan->fetchArray(SQLITE3_ASSOC);
        return $clanArray["rank"] == "Officer";
    }
    public function isMember($player) {
        $clan = $this->db->query("SELECT rank FROM master WHERE player='$player';");
        $clanArray = $clan->fetchArray(SQLITE3_ASSOC);
        return $clanArray["rank"] == "Member";
    }
    public function getPlayersInClanByRank($s, $clan, $rank) {
        if ($rank != "Leader") {
            $rankname = $rank . 's';
        } else {
            $rankname = $rank;
        }
        $team = "";
        $result = $this->db->query("SELECT player FROM master WHERE clan='$clan' AND rank='$rank';");
        $row = array();
        $i = 0;
        while ($resultArr = $result->fetchArray(SQLITE3_ASSOC)) {
            $row[$i]['player'] = $resultArr['player'];
            if ($this->getServer()->getPlayer($row[$i]['player']) instanceof Player) {
                $team .= TextFormat::ITALIC . TextFormat::AQUA . $row[$i]['player'] . TextFormat::GREEN . "[ON]" . TextFormat::RESET . TextFormat::WHITE . "||" . TextFormat::RESET;
            } else {
                $team .= TextFormat::ITALIC . TextFormat::AQUA . $row[$i]['player'] . TextFormat::RED . "[OFF]" . TextFormat::RESET . TextFormat::WHITE . "||" . TextFormat::RESET;
            }
            $i = $i + 1;
        }
        $s->sendMessage($this->formatMessage("~ *<$rankname> of |$clan|* ~", true));
        $s->sendMessage($team);
    }
    public function getAllAllies($s, $clan) {
        $team = "";
        $result = $this->db->query("SELECT clan2 FROM allies WHERE clan1='$clan';");
        $row = array();
        $i = 0;
        while ($resultArr = $result->fetchArray(SQLITE3_ASSOC)) {
            $row[$i]['clan2'] = $resultArr['clan2'];
            $team .= TextFormat::ITALIC . TextFormat::GREEN . $row[$i]['clan2'] . TextFormat::RESET . TextFormat::WHITE . "§2,§a " . TextFormat::RESET;
            $i = $i + 1;
        }
        $s->sendMessage($this->formatMessage("§3_____§2[§5§lAllies of §d*$clan*§r§2]§3_____", true));
        $s->sendMessage($team);
    }
    public function sendListOfTop10ClansTo($s) {
        $tc = "";
        $result = $this->db->query("SELECT clan FROM strength ORDER BY power DESC LIMIT 10;");
        $row = array();
        $i = 0;
        $s->sendMessage($this->formatMessage("§3_____§2[§5§lTop 10 BEST Clans§r§2]§3_____", true));
        while ($resultArr = $result->fetchArray(SQLITE3_ASSOC)) {
            $j = $i + 1;
            $cc = $resultArr['clan'];
            $pc = $this->getClanPower($cf);
            $dc = $this->getNumberOfPlayers($cf);
            $s->sendMessage(TextFormat::ITALIC . TextFormat::GOLD . "§6§l$j -> " . TextFormat::GREEN . "§r§d$cf" . TextFormat::GOLD . " §b| " . TextFormat::RED . "§e$pf STR" . TextFormat::GOLD . " §b| " . TextFormat::LIGHT_PURPLE . "§a$df/50" . TextFormat::RESET);
            $i = $i + 1;
        }
    }
    public function getPlayerClan($player) {
        $clan= $this->db->query("SELECT clan FROM master WHERE player='$player';");
        $clanArray = $clan->fetchArray(SQLITE3_ASSOC);
        return $clanArray["clan"];
    }
    public function getLeader($clan) {
        $leader = $this->db->query("SELECT player FROM master WHERE clan='$clan' AND rank='Leader';");
        $leaderArray = $leader->fetchArray(SQLITE3_ASSOC);
        return $leaderArray['player'];
    }
    public function clanExists($clan) {
        $result = $this->db->query("SELECT player FROM master WHERE clan='$clan';");
        $array = $result->fetchArray(SQLITE3_ASSOC);
        return empty($array) == false;
    }
    public function sameClan($player1, $player2) {
        $clan = $this->db->query("SELECT clan FROM master WHERE player='$player1';");
        $player1Clan = $clan->fetchArray(SQLITE3_ASSOC);
        $clan = $this->db->query("SELECT clan FROM master WHERE player='$player2';");
        $player2Clan = $clan->fetchArray(SQLITE3_ASSOC);
        return $player1Clan["clan"] == $player2Clan["clan"];
    }
    public function getNumberOfPlayers($clan) {
        $query = $this->db->query("SELECT COUNT(player) as count FROM master WHERE clan='$clan';");
        $number = $query->fetchArray();
        return $number['count'];
    }
    public function isClanFull($clan) {
        return $this->getNumberOfPlayers($clan) >= $this->prefs->get("MaxPlayersPerClan");
    }
    public function isNameBanned($name) {
        $bannedNames = file_get_contents($this->getDataFolder() . "BannedNames.txt");
        $isbanned = false;
        if (isset($name) && $this->antispam && $this->antispam->getProfanityFilter()->hasProfanity($name)) $isbanned = true;
        return (strpos(strtolower($bannedNames), strtolower($name)) > 0 || $isbanned);
    }/*
    public function newPlot($clan, $x1, $z1, $x2, $z2, string $level) {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO plots (faction, x1, z1, x2, z2, world) VALUES (:faction, :x1, :z1, :x2, :z2, :world);");
        $stmt->bindValue(":faction", $faction);
        $stmt->bindValue(":x1", $x1);
        $stmt->bindValue(":z1", $z1);
        $stmt->bindValue(":x2", $x2);
        $stmt->bindValue(":z2", $z2);
        $stmt->bindValue(":world", $level);
        $stmt->execute();
    }
    public function drawPlot($sender, $faction, $x, $y, $z, Level $level, $size) {
        $arm = ($size - 1) / 2;
        $block = new Snow();
        if ($this->cornerIsInPlot($x + $arm, $z + $arm, $x - $arm, $z - $arm, $level->getName())) {
            $claimedBy = $this->factionFromPoint($x, $z, $level->getName());
            $power_claimedBy = $this->getFactionPower($claimedBy);
            $power_sender = $this->getFactionPower($faction);
            if ($this->prefs->get("EnableOverClaim")) {
                if ($power_sender < $power_claimedBy) {
                    $sender->sendMessage($this->formatMessage("§dYou don't have enough power to overclaim this plot."));
                } else {
                    $sender->sendMessage($this->formatMessage("§aYou have enough STR power to overclaim this plot! §bNow, Type /f overclaim to overclaim this plot if you want."));
                }
                return false;
            } else {
                $sender->sendMessage($this->formatMessage("§cOverclaiming is disabled."));
                return false;
            }
        }
        $level->setBlock(new Vector3($x + $arm, $y, $z + $arm), $block);
        $level->setBlock(new Vector3($x - $arm, $y, $z - $arm), $block);
        $this->newPlot($faction, $x + $arm, $z + $arm, $x - $arm, $z - $arm, $level->getName());
        return true;
    }
    public function isInPlot(Player $player) {
        $x = $player->getFloorX();
        $z = $player->getFloorZ();
        $level = $player->getLevel()->getName();
        $result = $this->db->query("SELECT faction FROM plots WHERE $x <= x1 AND $x >= x2 AND $z <= z1 AND $z >= z2 AND world = '$level';");
        $array = $result->fetchArray(SQLITE3_ASSOC);
        return empty($array) == false;
    }
    public function factionFromPoint($x, $z, string $level) {
        $result = $this->db->query("SELECT faction FROM plots WHERE $x <= x1 AND $x >= x2 AND $z <= z1 AND $z >= z2 AND world = '$level';");
        $array = $result->fetchArray(SQLITE3_ASSOC);
        return $array["faction"];
    }
    public function inOwnPlot(Player $player) {
        $playerName = $player->getName();
        $x = $player->getFloorX();
        $z = $player->getFloorZ();
        $level = $player->getLevel()->getName();
        return $this->getPlayerFaction($playerName) == $this->factionFromPoint($x, $z, $level);
    }
    public function pointIsInPlot($x, $z, string $level) {
        $result = $this->db->query("SELECT faction FROM plots WHERE $x <= x1 AND $x >= x2 AND $z <= z1 AND $z >= z2 AND world = '$level';");
        $array = $result->fetchArray(SQLITE3_ASSOC);
        return !empty($array);
    }
    public function cornerIsInPlot($x1, $z1, $x2, $z2, string $level) {
        return($this->pointIsInPlot($x1, $z1, $level) || $this->pointIsInPlot($x1, $z2, $level) || $this->pointIsInPlot($x2, $z1, $level) || $this->pointIsInPlot($x2, $z2, $level));
    }*/
    public function formatMessage($string, $confirm = false) {
        if ($confirm) {
            return TextFormat::GREEN . "$string";
        } else {
            return TextFormat::YELLOW . "$string";
        }
    }
    public function motdWaiting($player) {
        $stmt = $this->db->query("SELECT player FROM motdrcv WHERE player='$player';");
        $array = $stmt->fetchArray(SQLITE3_ASSOC);
        return !empty($array);
    }
    public function getMOTDTime($player) {
        $stmt = $this->db->query("SELECT timestamp FROM motdrcv WHERE player='$player';");
        $array = $stmt->fetchArray(SQLITE3_ASSOC);
        return $array['timestamp'];
    }
    public function setMOTD($clan, $player, $msg) {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO motd (clan, message) VALUES (:clan, :message);");
        $stmt->bindValue(":clan", $clan);
        $stmt->bindValue(":message", $msg);
        $result = $stmt->execute();
        $this->db->query("DELETE FROM motdrcv WHERE player='$player';");
    }
    public function updateTag($playername) {
        $p = $this->getServer()->getPlayer($playername);
        $c = $this->getPlayerClan($playername);
        if (!$this->isInClan($playername)) {
            if(isset($this->purechat)){
                $levelName = $this->purechat->getConfig()->get("enable-multiworld-chat") ? $p->getLevel()->getName() : null;
                $nameTag = $this->purechat->getNametag($p, $levelName);
                $p->setNameTag($nameTag);
            }else{
                $p->setNameTag(TextFormat::ITALIC . TextFormat::YELLOW . "<$playername>");
            }
        }elseif(isset($this->purechat)) {
            $levelName = $this->purechat->getConfig()->get("enable-multiworld-chat") ? $p->getLevel()->getName() : null;
            $nameTag = $this->purechat->getNametag($p, $levelName);
            $p->setNameTag($nameTag);
        } else {
            $p->setNameTag(TextFormat::ITALIC . TextFormat::GOLD . "<$c> " .
                TextFormat::ITALIC . TextFormat::YELLOW . "<$playername>");
        }
    }
    public function onDisable() {
        $this->db->close();
    }
}
