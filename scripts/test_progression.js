#!/usr/bin/env node
"use strict";

const fs=require("fs");
const vm=require("vm");
const path=require("path");
const homepage=fs.readFileSync(path.resolve(__dirname,"..","index.html"),"utf8");
const start=homepage.indexOf("const MANAGER_PROGRESS_KEY=");
const end=homepage.indexOf("function managerLoad()",start);
if(start<0||end<0)throw new Error("Manager progression kernel not found");
const sandbox={};vm.createContext(sandbox);
vm.runInContext(homepage.slice(start,end)+"\nthis.api={managerXPForLevel,managerCampaignReward,managerApplyXP};",sandbox);
const {managerXPForLevel,managerCampaignReward,managerApplyXP}=sandbox.api;

if(managerXPForLevel(1)!==100||managerXPForLevel(4)!==250)throw new Error("XP curve mismatch");
const rows=[{myG:2,oppG:0,stars:3},{myG:1,oppG:1,stars:1},{myG:0,oppG:1,stars:0}];
const reward=managerCampaignReward(rows,false,"hard");
if(reward.total!==103||reward.matches!==3||reward.wins!==1||reward.stars!==4)throw new Error("campaign reward mismatch");
const champion=managerCampaignReward(rows,true,"legend");
if(champion.total!==253)throw new Error("victory/difficulty bonus mismatch");
const levelled=managerApplyXP({level:1,xp:90,totalXP:90,campaigns:2,wins:1},410);
if(levelled.profile.level!==4||levelled.profile.xp!==50||levelled.levelsGained!==3||levelled.profile.totalXP!==500)throw new Error("multi-level progression mismatch");
if(!homepage.includes("if(G.progressAwarded)return null")||!homepage.includes("G.progressAwarded = false"))throw new Error("single-award guard missing");
for(const id of ["manager-progress","go-progress","t-progress"])if(!homepage.includes('id="'+id+'"'))throw new Error("progress UI missing: "+id);
console.log("Manager progression tests passed.");
