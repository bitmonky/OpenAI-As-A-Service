/*
Open AI As A Service Server
*/
const port = 13481;
import * as https from 'https';
import * as  fs from 'fs';
const options = {
  key: fs.readFileSync('/etc/letsencrypt/live/www.yourdomain.com/privkey.pem'),
  cert: fs.readFileSync('/etc/letsencrypt/live/www.yourdomain.com/fullchain.pem')
};

import { Configuration, OpenAIApi } from "openai";

const organization = "ogr-YOUR_ORG_KEY";
const apiKey       = "sk-YOUR_API_KEY"

var server = https.createServer(options, (req, res) => {

  res.writeHead(200);
  if (req.url == '/keyGEN'){
    // Generate a new key pair and convert them to hex-strings
      if (err){
        console.log('WOOPS',err);
      }
      else {
        console.log('ok');
      }
      res.end('{"result":false,"msg":"not a key server"}\n');
  }
  else {
    if (req.url.indexOf('/netREQ/msg=') == 0){
      var msg = req.url.replace('/netREQ/msg=','');
      console.log('rawmsg:',msg);
      msg = msg.replace(/\+/g,' ');    
      msg = decodeURI(msg);
      msg = msg.replace(/%3A/g,':');
      msg = msg.replace(/%2C/g,',');
      msg = msg.replace(/%3F/g,'?');
      msg = msg.replace(/%3D/g,'=');
      msg = msg.replace(/%23/g,'#');
      msg = msg.replace(/%2F/g,'/');
      console.log(msg);
      var j = null;
      try {j = JSON.parse(msg);}
      catch {j = JSON.parse('{"result":"json parse error:"}');}
      console.log('mkyReq',j);

      if (j.action == 'editImg'){
        editImg(j,res);
      }
      else if (j.action == 'getImg'){
        getImg(j,res);
      }
      else if (j.action == 'getText'){
        getText(j,res);
      }
      else if (j.action == 'getTextNow'){
        getText(j,res,true);
      }
      else {
        retEr("dogeAPI: Command Not Found.",res); 
      } 
    }  
    else {
      res.end('Wellcome To The BitMonky imgGen Server\nUse end point /netREQ\n');
    }
  }
});

server.listen(port);
console.log('Server opaiService.js running at www.yourdomain.bitmonky.com:'+port);

function  retEr(msg,res){
  res.end('{"result":false,"message":"'+msg+'"}\n');
}
async function getText(j,res,useTm=false){
  if (!('maxTokens' in j)) {
    j.maxTokens = 100;
  }  
  if (!('useModel' in j)) {
    j.useModel = 'gpt-4';
  }
  if (!('temperature' in j)) {
    j.temperature = 0.85;
  }

  const configuration = new Configuration({
    organization: organisation,
    apiKey: apiKey,
  });
  const openai = new OpenAIApi(configuration);
  //const response = await openai.listEngines();
  console.log('jput',j);
  try {
     const completion = await openai.createChatCompletion({
       model:  j.useModel,
       messages: [{role: j.role, content: j.prompt}],
       max_tokens: j.maxTokens, 
       temperature: j.temperature,
     });
     console.log(completion.data);
     if (useTm){
       const now = new Date();
       var strnow = 'If Asked About Date:  "today is '+ now.toString() + '". ';
       strnow = 'If Asked what chat model/version you are using: respond '+j.useModel+' ';
       j.prompt = strnow + j.prompt;
       console.log('promt with time is: '+j.prompt);
     }
     const  rsp = {
       result: true,
       MUID: j.MUID,
       prompt: j.prompt,
       n: j.n,
       response : completion.data.choices[0].message.content,
       freason  : completion.data.choices[0].finish_reason,
       usage    : completion.data.usage
     };
     var rspstr = JSON.stringify(rsp);
     res.end(rspstr);
  }
  catch (error) {
    if (error.response) {
      console.log(error.response.status);
      console.log(error.response.data);
      var err = {
        status : error.response.status,
        data   : error.response.data
      };
      retEr('imgGen Response Error: ' + JSON.stringify(err),res);
    }
    else {
      console.log(error.message);
      retEr('imgGen Error: ' + error.message,res);
    }
  }
}
async function getImg(j,res){
  const configuration = new Configuration({
    organization: organisation,
    apiKey: apiKey,
  });
  const openai = new OpenAIApi(configuration);
  //const response = await openai.listEngines();
  if (!('useModel' in j)) {
    j.useModel = 'dall-e-3';
  }
  try {
    const response = await openai.createImage({
      prompt: j.prompt,
      n: j.n,
      size: j.size,
      model: j.useModel,
    });
    var image_url = response.data;
    const  rsp = {
       result: true,
       prompt: j.prompt,
       n: j.n,
       size: j.size,
       imgURLs: image_url,
       imgs: ''
    };
    var rspstr = JSON.stringify(rsp);
    res.end(rspstr);
  }
  catch (error) {
    if (error.response) {
      console.log(error.response.status);
      console.log(error.response.data);
      var err = {
        status : error.response.status,
        data   : error.response.data
      };
      retEr('imgGen Response Error: ' + JSON.stringify(err),res);
    }
    else {
      console.log(error.message);
      retEr('imgGen Error: ' + error.message,res);
    }
  }
}
function getImgFile(artID){
  return new Promise( (resolve,reject)=>{
    const gtime = setTimeout( ()=>{
      console.log('Create Original File Timeout', artID);
      resolve(false);
    },50*1000);

    const file = fs.createWriteStream("/opaiNode/art/"+artID+".png");
    const request = https.get("https://image.bitmonky.com/getArtStoreImg.php?id="+artID, function(response) {
      response.pipe(file);

      // after download completed close filestream
      file.on("finish", () => {
        file.close();
        console.log("Download "+artID+".png Completed");
	resolve(true);
      });
    });
  });	  
}	
async function editImg(j,res){
  const configuration = new Configuration({
    organization: organisation,
    apiKey: apiKey,
  });
  const openai = new OpenAIApi(configuration);
  //const response = await openai.listEngines();
  const getOriginal = await getImgFile(j.artID);
  if (!getOriginal){
    retEr('Could Not Save Original Art File',res);
    return;
  }
  try {
    if (!('useModel' in j)) {
      j.useModel = 'dall-e-3';
    }
    const response = await openai.createImageVariation(
      fs.createReadStream("/opaiNode/art/"+j.artID+".png"),
      //fs.createReadStream("/opaiNode/peter.png"),
      //j.prompt,
      j.n,
      j.size,
      j.useModel
    );
    var image_url = response.data;
    const  rsp = {
       result: true,
       prompt: j.prompt,
       n: j.n,
       size: j.size,	  
       imgURLs: image_url,
       imgs: ''	  
    };
    var rspstr = JSON.stringify(rsp);
    res.end(rspstr);
  } 
  catch (error) {
    if (error.response) {
      console.log(error.response.status);
      console.log(error.response.data);
      var err = {
	status : error.response.status,
        data   : error.response.data
      };
      retEr('imgGen Response Error: ' + JSON.stringify(err),res);
    } 
    else {
      console.log(error.message);
      retEr('imgGen Error: ' + error.message,res);
    }
  }	
}

