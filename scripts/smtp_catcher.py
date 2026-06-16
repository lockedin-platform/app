#!/usr/bin/env python3
"""Tiny local SMTP server that prints received messages. Used to test the app's email pipeline."""
import asyncio
from aiosmtpd.controller import Controller


class Handler:
    async def handle_DATA(self, server, session, envelope):
        print("=== EMAIL RECEIVED ===", flush=True)
        print("From:", envelope.mail_from, flush=True)
        print("To:", envelope.rcpt_tos, flush=True)
        data = envelope.content.decode("utf8", errors="replace")
        # print subject + a snippet
        for line in data.splitlines():
            if line.lower().startswith("subject:"):
                print(line, flush=True)
        print("Body length:", len(data), "bytes", flush=True)
        print("======================", flush=True)
        return "250 Message accepted for delivery"


if __name__ == "__main__":
    controller = Controller(Handler(), hostname="127.0.0.1", port=1025)
    controller.start()
    print("SMTP catcher listening on 127.0.0.1:1025", flush=True)
    try:
        asyncio.get_event_loop().run_forever()
    except KeyboardInterrupt:
        controller.stop()
