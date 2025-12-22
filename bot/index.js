const TelegramBot = require('node-telegram-bot-api');
const fs = require('fs');
const path = require('path');
const bot = new TelegramBot('8458034643:AAH1KVd6D_FeBOidKtm20HyC4OLFoEPxX4A', { polling: true });

bot.onText(/\/start/, (msg) => {
  const photoPath = path.join(__dirname, 'bg.jpg');
  const photo = fs.createReadStream(photoPath);

  bot.sendPhoto(msg.chat.id, photo, {
    caption: `<b>Добро пожаловать в @MineDrop —
✨ популярную мини-игру в Telegram, в которую уже играют тысячи пользователей!

<blockquote>
🎁 Ежедневные награды и бонусы
🔥 Регулярные промокоды и подарки
🚀 Быстрый и увлекательный геймплей
💸 Возможность моментальных выплат
🎯 Ивенты, задания и приятные призы
</blockquote>

Полезные каналы для игроков:
🔥 Промокоды — @minedrop95
🍀 Резервный канал — @minedropreserve
</b>`,
    parse_mode: "HTML"
  });
});