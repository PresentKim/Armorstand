<?php

/**
 * @name ArmorStand
 * @main Securti\armorstand\ArmorStand
 * @author ["Flug-in-Fabrik", "Securti"]
 * @version 0.1
 * @api 3.10.0
 * @description PMMP에 갑옷거치대를 구현합니다.
 * 해당 플러그인 (ArmrorStand)은 Fabrik-EULA에 의해 보호됩니다
 * Fabrik-EULA : https://github.com/Flug-in-Fabrik/Fabrik-EULA
 */
namespace Securti\armorstand;

use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\entity\Creature;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\inventory\BaseInventory;
use pocketmine\inventory\ContainerInventory;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\types\WindowTypes;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\NetworkLittleEndianNBTStream;
use pocketmine\math\Vector3;
use pocketmine\utils\Config;
use pocketmine\scheduler\Task;
use ifteam\SimpleArea\database\area\AreaSection;
use ifteam\SimpleArea\database\area\AreaProvider;

class ArmorStand extends PluginBase implements Listener {
	public static $instance;
	private $cool;
	public $data, $item, $code, $tag;
	public $windowId;
	public $prefix = "§l§b[알림] §r§7";

	public static function getInstance(){
		return self::$instance;
	}

	public function onLoad(){
		self::$instance = $this;

		Entity::registerEntity(ArmorStandEntity::class, true, ["minecraft:armor_stand"]);
	}

	public function onEnable(){
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		@mkdir($this->getDataFolder());
		$this->entityData = new Config($this->getDataFolder() . "EntityData.yml", Config::YAML);
		$this->data = $this->entityData->getAll();

		$task = new ArmorStandTask($this);
		$this->getScheduler()->scheduleRepeatingTask($task, 1);
	}

	public function onJoin(PlayerJoinEvent $e){
		$player = $e->getPlayer();
		$name = strtolower($player->getName());
		
		if(!isset($this->cool[$name])){
			$this->cool[$name] = time();
		}
	}

	public function onInteract(PlayerInteractEvent $event){
		$prefix = $this->prefix;

		$player = $event->getPlayer();
		$name = strtolower($player->getName());

		$inventory = $player->getInventory();
		$item = $inventory->getItemInHand();
		$id = $item->getId();

		$block = $event->getBlock();

		$area = AreaProvider::getInstance()->getArea($player->getLevel(), $block->getX(), $block->getZ());
		
		if(!isset($this->cool[$name])) return true;

		if($this->cool[$name] == time())
			return true;

		$this->cool[$name] = time();

		if($id == 425 && ($player->isOp() || $area instanceof AreaSection && $area->isResident($player))){
			$nbt = new CompoundTag("", [new ListTag("Pos", [new DoubleTag("", $block->getX() + 0.5),new DoubleTag("", $block->getY() + 1),new DoubleTag("", $block->getZ() + 0.5)]),new ListTag("Motion", [new DoubleTag("", 0),new DoubleTag("", 0),new DoubleTag("", 0)]),
					new ListTag("Rotation", [new FloatTag(0, 0),new FloatTag(0, 0)])]);

			$ran = mt_rand(1, 1000000000);
			$nbt->setString("owner", $name);
			$nbt->setString("code", $name . "|" . $ran);

			$entity = Entity::createEntity("stand", $player->getLevel(), $nbt);
			$entity->setNameTag("§r§b" . $name . "§f의 갑옷 거치대");
			$entity->setItem("hand", Item::get(0, 0, 0));
			$entity->setItem("helmet", Item::get(0, 0, 0));
			$entity->setItem("chestplate", Item::get(0, 0, 0));
			$entity->setItem("leggings", Item::get(0, 0, 0));
			$entity->setItem("boots", Item::get(0, 0, 0));
			$entity->setHealth(100000);
			$entity->setMaxHealth(100000);
			$entity->setNameTagVisible(true);
			$entity->setNameTagAlwaysVisible(true);
			$entity->spawnToAll();

			$item = $this->getNBT(Item::get(0, 0, 0));

			$this->data[$name . "|" . $ran]["owner"] = $name;
			$this->data[$name . "|" . $ran]["tag"] = "§r§b" . $name . "§f의 갑옷 거치대";
			$this->data[$name . "|" . $ran]["yaw"] = 0;
			// $this->data[$name."|".$ran]["pitch"] = 0; 갑옷거치대는 pitch가 변화가 없더군요... 껄껄
			$this->data[$name . "|" . $ran]["scale"] = 100;
			$this->data[$name . "|" . $ran]["hand"] = $item;
			$this->data[$name . "|" . $ran]["helmet"] = $item;
			$this->data[$name . "|" . $ran]["chestplate"] = $item;
			$this->data[$name . "|" . $ran]["leggings"] = $item;
			$this->data[$name . "|" . $ran]["boots"] = $item;

			$this->save();

			$inventory->removeItem(Item::get(425, 0, 1));

			$player->sendMessage($prefix . "갑옷 거치대를 생성하였습니다");
		}
	}

	public function onDamage(EntityDamageEvent $event){
		$entity = $event->getEntity();

		if($entity instanceof ArmorStandEntity){
			$event->setCancelled(true);
			if($event instanceof EntityDamageByEntityEvent){
				$damager = $event->getDamager();
				if($damager instanceof Player){
					$name = strtolower($damager->getName());
					$owner = strtolower($entity->getOwner());
					if($owner === $name or $player->isOp()){
						$this->code[$name] = $entity->getCode();
						// $this->tag[$name] = $entity->getTag();
						$this->showUI1($damager);
					}
				}
			}
		}
	}

	public function onInventoryChange(InventoryTransactionEvent $event){
		$transaction = $event->getTransaction();
		$player = $transaction->getSource();
		if($player instanceof Player){
			foreach($transaction->getInventories() as $inventory){
				if($inventory instanceof StandInv){
					foreach($transaction->getActions() as $action){
						$source = $action->getSourceItem();
						$target = $action->getTargetItem();
						if($source->getNamedTagEntry("ainv") !== null or $target->getNamedTagEntry("ainv") !== null){
							$event->setCancelled(true);
						}
					}
				}
			}
		}
	}

	public function onInventoryClose(InventoryCloseEvent $e){
		$prefix = $this->prefix;
		$player = $e->getPlayer();
		$name = strtolower($player->getName());
		$inventory = $e->getInventory();
		if($inventory instanceof StandInv){
			$code = $this->code[$name];
			// $tag = $this->tag[$name];
			foreach($this->getServer()->getLevels() as $level){
				foreach($level->getEntities() as $entity){
					if($entity instanceof ArmorStandEntity){
						$code = $entity->getCode();
						$pcode = $this->code[$name];
						if($code === $pcode){
							$this->data[$code]["hand"] = $this->getNBT($inventory->getItem(9));
							$this->data[$code]["helmet"] = $this->getNBT($inventory->getItem(11));
							$this->data[$code]["chestplate"] = $this->getNBT($inventory->getItem(13));
							$this->data[$code]["leggings"] = $this->getNBT($inventory->getItem(15));
							$this->data[$code]["boots"] = $this->getNBT($inventory->getItem(17));

							$player->sendMessage($prefix . "갑옷 거치대 갑옷을 수정하였습니다");
						}
					}
				}
			}
		}
	}

	public function getUI(DataPacketReceiveEvent $e){
		$pack = $e->getPacket();
		$player = $e->getPlayer();
		$name = strtolower($player->getName());
		$prefix = $this->prefix;
		if($pack instanceof ModalFormResponsePacket and $pack->formId == 66778800){
			$button = json_decode($pack->formData, true);
			if($button == 1){
				$this->showUI2($player);
			}elseif($button == 2){
				$this->showGUI($player);
			}elseif($button == 3){
				$this->remove($player);
			}
		}elseif($pack instanceof ModalFormResponsePacket and $pack->formId == 66778811){
			$button = json_decode($pack->formData, true);
			foreach($this->getServer()->getLevels() as $level){
				foreach($level->getEntities() as $entity){
					if($entity instanceof ArmorStandEntity){
						$code = $entity->getCode();
						$pcode = $this->code[$name];
						if($code === $pcode){
							if($button[0] == null){
								$entity->setNameTag("§r§b" . $name . "§f의 갑옷 거치대");
							}else{
								$entity->setNameTag($button[0]);
							}
							$entity->setNameTagVisible(true);
							$entity->setNameTagAlwaysVisible(true);
							$this->data[$code]["tag"] = $button[0];
							$this->data[$code]["yaw"] = $button[1];
							$this->data[$code]["scale"] = $button[2];
							$this->save();
							if($button[2] <= 0){
								$entity->setScale(0.5);
							}else{
								$entity->setScale($button[2] / 100);
							}
							$entity->teleport(new Vector3($entity->getX(), $entity->getY(), $entity->getZ()), $button[1], 0);
							$player->sendMessage($prefix . "갑옷 거치대 정보를 수정하였습니다");
						}
					}
				}
			}
		}
	}

	public function remove($player){
		$prefix = $this->prefix;
		$name = strtolower($player->getName());
		$inventory = $player->getInventory();
		$code = $this->code[$name];
		foreach($this->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $entity){
				if($entity instanceof ArmorStandEntity){
					$code = $entity->getCode();
					$pcode = $this->code[$name];
					if($code === $pcode){
						$hand = $this->getItem($this->data[$code]["hand"]);
						$helmet = $this->getItem($this->data[$code]["helmet"]);
						$chestplate = $this->getItem($this->data[$code]["chestplate"]);
						$leggings = $this->getItem($this->data[$code]["leggings"]);
						$boots = $this->getItem($this->data[$code]["boots"]);
						if($hand->getId() != 0){
							$inventory->addItem($hand);
						}
						if($helmet->getId() != 0){
							$inventory->addItem($helmet);
						}
						if($chestplate->getId() != 0){
							$inventory->addItem($chestplate);
						}
						if($leggings->getId() != 0){
							$inventory->addItem($leggings);
						}
						if($boots->getId() != 0){
							$inventory->addItem($boots);
						}
						$inventory->addItem(Item::get(425, 0, 1));
						$entity->close();

						$player->sendMessage($prefix . "갑옷 거치대를 회수하였습니다");
					}
				}
			}
		}
	}

	public function showGUI(Player $player){
		$player->addWindow(new StandInv($this, [], $player->asVector3(), 27, "§l§b[ArmorStand] §f손,투구,흉갑,바지,부츠"));
	}

	public function showUI1(Player $player){
		$encode = json_encode(["type" => "form","title" => "§l§b[ArmorStand]","content" => "","buttons" => [["text" => "§l§b· §fUI 닫기"],["text" => "§l§b· §f정보 관리"],["text" => "§l§b· §f갑옷 관리"],["text" => "§l§b· §f거치대 회수"]]]);

		$pack = new ModalFormRequestPacket();
		$pack->formId = 66778800;
		$pack->formData = $encode;
		$player->dataPacket($pack);
	}

	public function showUI2(Player $player){
		$name = strtolower($player->getName());
		$code = $this->code[$name];
		$tag = $this->data[$code]["tag"];

		$encode = json_encode(["type" => "custom_form","title" => "§l§b[ArmorStand]",
				"content" => [["type" => "input","text" => "§l§b· §f태그를 입력해주세요","default" => $tag],["type" => "slider","text" => "§l§b· §f회전 방향을 선택해주세요 ","min" => 0,"max" => 360],["type" => "slider","text" => "§l§b· §f크기 수치를 선택해주세요 (단위 : %%) ","min" => 50,"max" => 150]]]);

		$pack = new ModalFormRequestPacket();
		$pack->formId = 66778811;
		$pack->formData = $encode;
		$player->dataPacket($pack);
	}

	public function save(){
		$this->entityData->setAll($this->data);
		$this->entityData->save();
	}

	public function setItem($entity){
		$code = $entity->getCode();
		if(isset($this->data[$code]["hand"])){
			$hand = $this->getItem($this->data[$code]["hand"]);
			$pk = new MobEquipmentPacket();
			$pk->entityRuntimeId = $entity->getId();
			$pk->item = $hand;
			$pk->inventorySlot = 0;
			$pk->hotbarSlot = 0;
			foreach($this->getServer()->getOnlinePlayers() as $player){
				$player->dataPacket($pk);
			}
		}
	}

	public function setArmor($entity){
		$code = $entity->getCode();
		if(isset($this->data[$code]["helmet"]) and isset($this->data[$code]["chestplate"]) and isset($this->data[$code]["leggings"]) and isset($this->data[$code]["boots"])){
			$helmet = $this->getItem($this->data[$code]["helmet"]);
			$chestplate = $this->getItem($this->data[$code]["chestplate"]);
			$leggings = $this->getItem($this->data[$code]["leggings"]);
			$boots = $this->getItem($this->data[$code]["boots"]);

			$pk = new MobArmorEquipmentPacket();
			$pk->entityRuntimeId = $entity->getId();
			$pk->head = $helmet;
			$pk->chest = $chestplate;
			$pk->legs = $leggings;
			$pk->feet = $boots;
			$pk->encode();
			$pk->isEncoded = true;

			foreach($this->getServer()->getOnlinePlayers() as $player){
				$player->dataPacket($pk);
			}
		}
	}

	public function getNBT($item){
		return $item->jsonSerialize();
	}

	public function getItem($item){
		return Item::jsonDeserialize($item);
	}
}

class ArmorStandEntity extends Creature {
	const NETWORK_ID = self::ARMOR_STAND;
	public $width = 0.5;
	public $height = 1.975;
	public $owner = null;
	private $code = null;
	private $item;

	public function initEntity(): void{
		parent::initEntity();
	}

	public function saveNBT(): void{
		parent::saveNBT();
	}

	public function getName(): string{
		return "armorstand";
	}

	public function setOwner(Player $player){
		$name = strtolower($player->getName());

		$this->owner = $name;
	}

	public function getOwner(){
		if($this->owner !== null){
			return $this->owner;
		}else{
			return "none";
		}
	}

	public function getCode(){
		if($this->code !== null){
			return $this->code;
		}else{
			return "none";
		}
	}

	public function setItem($index, $item){
		$this->item[$index] = $this->getNBT($item);
	}

	public function getItem($index){
		if(isset($this->item[$index])){
			return $this->getItem2($this->item[$index]);
		}else{
			return Item::get(0, 0, 0);
		}
	}

	public function getNBT($item){
		return $item->jsonSerialize();
	}

	public function getItem2($item){
		return Item::jsonDeserialize($item);
	}

	public function setData($type, $nbt){
		if($type === "owner"){
			$this->owner = $nbt;
		}elseif($type === "code"){
			$this->code = $nbt;
		}
	}
}

class StandInv extends ContainerInventory {
	private $plugin;
	protected $size;
	protected $title;

	public function __construct(ArmorStand $plugin, array $items, Vector3 $holder, int $size = null, string $title = ""){
		$this->plugin = $plugin;

		$this->title = $title;
		$this->size = $size;

		parent::__construct($holder, $items, $size, $title);
	}

	public function onOpen(Player $player): void{
		BaseInventory::onOpen($player);

		$block = Block::get(54, 0);
		$block->x = (int) $player->x;
		$block->y = (int) $player->y + 4;
		$block->z = (int) $player->z;
		$player->getLevel()->sendBlocks([$player], [$block]);

		$tag = new CompoundTag();
		$tag->setString("CustomName", $this->title);

		$pk = new BlockActorDataPacket();
		$pk->x = $block->x;
		$pk->y = $block->y;
		$pk->z = $block->z;
		$pk->namedtag = (new NetworkLittleEndianNBTStream())->write($tag);
		$player->sendDataPacket($pk);

		$pk = new ContainerOpenPacket();
		$pk->windowId = $player->getWindowId($this);
		$pk->type = WindowTypes::CONTAINER;
		$pk->x = $block->x;
		$pk->y = $block->y;
		$pk->z = $block->z;
		$player->dataPacket($pk);

		$name = strtolower($player->getName());

		for($i = 0; $i <= 26; $i ++){
			if($i <= 8 or $i >= 18 or $i == 10 or $i == 12 or $i == 14 or $i == 16){
				$item = Item::get(154, 0, 1);
				$item->setNamedTagEntry(new StringTag("ainv", "true"));
				$this->setItem($i, $item);
			}else{
				foreach($this->plugin->getServer()->getLevels() as $level){
					foreach($level->getEntities() as $entity){
						if($entity instanceof ArmorStandEntity){
							$code = $entity->getCode();
							$pcode = $this->plugin->code[$name];
							if($code === $pcode){
								$hand = $this->plugin->getItem($this->plugin->data[$code]["hand"]);
								$helmet = $this->plugin->getItem($this->plugin->data[$code]["helmet"]);
								$chestplate = $this->plugin->getItem($this->plugin->data[$code]["chestplate"]);
								$leggings = $this->plugin->getItem($this->plugin->data[$code]["leggings"]);
								$boots = $this->plugin->getItem($this->plugin->data[$code]["boots"]);
								if($i == 9){
									$this->setItem($i, $hand);
								}elseif($i == 11){
									$this->setItem($i, $helmet);
								}elseif($i == 13){
									$this->setItem($i, $chestplate);
								}elseif($i == 15){
									$this->setItem($i, $leggings);
								}elseif($i == 17){
									$this->setItem($i, $boots);
								}
							}
						}
					}
				}
			}
		}
		$this->sendContents($player);
	}

	public function onClose(Player $player): void{
		BaseInventory::onClose($player);

		$pk = new ContainerClosePacket();
		$pk->windowId = $player->getWindowId($this);
		$player->dataPacket($pk);
	}

	public function getNetworkType(): int{
		return WindowTypes::CONTAINER;
	}

	public function getName(): string{
		return $this->title;
	}

	public function getDefaultSize(): int{
		return $this->size;
	}
}

class ArmorStandTask extends Task {
	private $plugin;

	public function __construct(ArmorStand $plugin){
		$this->plugin = $plugin;
	}

	public function onRun($currentTick){
		foreach($this->plugin->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $entity){
				if($entity instanceof ArmorStandEntity){
					if(isset($this->plugin->data[$entity->namedtag->getString("code")])){
						$this->plugin->setItem($entity);
						$this->plugin->setArmor($entity);
						if($entity->namedtag->hasTag("owner", StringTag::class)){
							$entity->setData("owner", $entity->namedtag->getString("owner"));
						}
						if($entity->namedtag->hasTag("code", StringTag::class)){
							$entity->setData("code", $entity->namedtag->getString("code"));
						}
						if($this->plugin->data[$entity->namedtag->getString("code")]["scale"]){
							$entity->setScale($this->plugin->data[$entity->namedtag->getString("code")]["scale"] / 100);
						}
						if($entity->getYaw() != $this->plugin->data[$entity->namedtag->getString("code")]["yaw"]){
							$entity->teleport(new Vector3($entity->getX(), $entity->getY(), $entity->getZ()), $this->plugin->data[$entity->namedtag->getString("code")]["yaw"], 0);
						}
					}
				}
			}
		}
	}
}
