from ConfigParser import ConfigParser
import os

class ToolserverConfig:
    def __init__(self, conf=os.path.expanduser("~/.my.cnf")):
        self.config = ConfigParser()
        self.config.read(conf)
        try:
            self.section = self.config.sections()[0]
        except IndexError:
            raise ValueError("Malformed config file")

    @property
    def user(self):
        return self.config.get(self.section, "user")

    @property
    def password(self):
        return self.config.get(self.section, "password")[1:-1]

    @property
    def host(self):
        return self.config.get(self.section, "host")[1:-1]

if __name__ == "__main__":
    t = ToolserverConfig()
    print t.user
    print t.password
    print t.host
