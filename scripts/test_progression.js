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
vm.runInContext(homepage.slice(start,end)+"\nthis.api={managerXPForLevel,masteryXPForLevel,managerCampaignReward,managerApplyXP,managerNormalizeProfile,managerCampaignGrade,managerApplyMastery};",sandbox);
const {managerXPForLevel,masteryXPForLevel,managerCampaignReward,managerApplyXP,managerNormalizeProfile,managerCampaignGrade,managerApplyMastery}=sandbox.api;

if(managerXPForLevel(1)!==100||managerXPForLevel(4)!==250)throw new Error("XP curve mismatch");
const rows=[{myG:2,oppG:0,stars:3},{myG:1,oppG:1,stars:1},{myG:0,oppG:1,stars:0}];
const reward=managerCampaignReward(rows,false,"hard");
if(reward.total!==103||reward.matches!==3||reward.wins!==1||reward.stars!==4)throw new Error("campaign reward mismatch");
const champion=managerCampaignReward(rows,true,"legend");
if(champion.total!==253)throw new Error("victory/difficulty bonus mismatch");
const levelled=managerApplyXP({level:1,xp:90,totalXP:90,campaigns:2,wins:1},410);
if(levelled.profile.level!==4||levelled.profile.xp!==50||levelled.levelsGained!==3||levelled.profile.totalXP!==500)throw new Error("multi-level progression mismatch");
const migrated=managerNormalizeProfile({level:3,xp:12,totalXP:300,campaigns:4,wins:2});
if(migrated.level!==3||migrated.mastery.ucl.level!==1||migrated.mastery.copa.campaigns!==0)throw new Error("v1 profile migration mismatch");
if(masteryXPForLevel(1)!==120||masteryXPForLevel(4)!==300)throw new Error("mastery XP curve mismatch");
const mastered=managerApplyMastery(migrated,"copa",560,true,"A");
if(mastered.mastery.level!==4||mastered.mastery.xp!==20||mastered.mastery.campaigns!==1||mastered.mastery.wins!==1||mastered.mastery.bestGrade!=="A")throw new Error("mastery multi-level award mismatch");
if(mastered.profile.mastery.ucl.totalXP!==0||mastered.profile.mastery.wc.totalXP!==0)throw new Error("mastery leaked across tournaments");
if(managerCampaignGrade(rows)!=="C")throw new Error("campaign grade mismatch");
if(!homepage.includes("if(G.progressAwarded)return null")||!homepage.includes("G.progressAwarded = false"))throw new Error("single-award guard missing");
for(const id of ["manager-progress","go-progress","t-progress"])if(!homepage.includes('id="'+id+'"'))throw new Error("progress UI missing: "+id);
for(const mode of ["ucl","copa","wc"])if(!homepage.includes('data-mastery="'+mode+'"'))throw new Error("mastery UI missing: "+mode);
// Hostile / corrupted saved profiles (localStorage or importSave) must normalise to
// bounded integers, keep bestGrade inside its whitelist and never hang the level loop.
{
  const bad=[
    {level:"abc",xp:"5",totalXP:null,campaigns:"x",wins:-4,mastery:{ucl:{level:null,xp:1e300,bestGrade:"<img src=x onerror=alert(1)>"}}},
    {level:1e300,xp:1e300,totalXP:Infinity,mastery:{copa:{level:"9",xp:"55",totalXP:{},campaigns:NaN,wins:[],bestGrade:"S"}}},
    {level:-3,xp:-100,mastery:"nope"},
    "string",[],null,42
  ];
  const t0=Date.now();
  for(const raw of bad){
    const p=managerNormalizeProfile(raw);
    for(const k of ["level","xp","totalXP","campaigns","wins"]){
      if(!Number.isInteger(p[k])||p[k]<0)throw new Error("profile."+k+" not a safe integer for "+JSON.stringify(raw)+": "+p[k]);
    }
    if(p.level<1||p.level>9999)throw new Error("profile level out of range: "+p.level);
    for(const mode of ["ucl","copa","wc"]){
      const m=p.mastery[mode];
      for(const k of ["level","xp","totalXP","campaigns","wins"]){
        if(!Number.isInteger(m[k])||m[k]<0)throw new Error("mastery."+mode+"."+k+" not a safe integer: "+m[k]);
      }
      if(m.level<1||m.level>9999)throw new Error("mastery level out of range: "+m.level);
      if(m.bestGrade!==null&&!["S","A","B","C"].includes(m.bestGrade))throw new Error("bestGrade escaped whitelist: "+m.bestGrade);
    }
  }
  if(managerNormalizeProfile(bad[1]).mastery.copa.bestGrade!=="S")throw new Error("valid bestGrade must survive normalisation");
  if(managerNormalizeProfile({xp:"5"}).xp!==5)throw new Error("numeric strings must be coerced, not concatenated");
  const hostile=managerApplyMastery({mastery:{ucl:{level:"1",xp:"5",bestGrade:"<b>"}}},"ucl",54,true,"<i>");
  if(hostile.mastery.xp!==59||hostile.mastery.bestGrade!==null)throw new Error("mastery update on hostile data wrong");
  if(Date.now()-t0>1500)throw new Error("hostile profile normalisation is too slow (level loop unbounded?)");
}
console.log("Manager progression tests passed.");
